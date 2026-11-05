//go:build linux

// health-collector samples system metrics from /proc every few seconds and
// atomically rewrites a JSON status file that Landing/health.php reads.
// It opens no network ports and reads no network input.
package main

import (
	"encoding/json"
	"flag"
	"log"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Whole disks only; partitions would double-count I/O.
var wholeDisk = regexp.MustCompile(`^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$`)

type counters struct {
	at                    time.Time
	cpuBusy, cpuTotal     uint64
	readBytes, writeBytes uint64
	rxBytes, txBytes      uint64
}

type Status struct {
	GeneratedAt   int64      `json:"generated_at"`
	IntervalSecs  int        `json:"interval"`
	CPUPercent    float64    `json:"cpu_percent"`
	Cores         int        `json:"cores"`
	Load          [3]float64 `json:"load"`
	Memory        Memory     `json:"memory"`
	Disk          Disk       `json:"disk"`
	DiskIO        Rate       `json:"disk_io"`
	Network       Rate       `json:"network"`
	UptimeSeconds int64      `json:"uptime_seconds"`
}

type Memory struct {
	Total     uint64 `json:"total"`
	Used      uint64 `json:"used"`
	SwapTotal uint64 `json:"swap_total"`
	SwapUsed  uint64 `json:"swap_used"`
}

type Disk struct {
	Total uint64 `json:"total"`
	Used  uint64 `json:"used"`
}

// Rate holds bytes per second; for disk I/O they are reads and writes,
// for the network they are received and sent.
type Rate struct {
	In  float64 `json:"in"`
	Out float64 `json:"out"`
}

func readFile(path string) string {
	data, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	return string(data)
}

func parseUints(fields []string) []uint64 {
	values := make([]uint64, len(fields))
	for i, field := range fields {
		values[i], _ = strconv.ParseUint(field, 10, 64)
	}
	return values
}

func readCPU() (busy, total uint64) {
	for _, line := range strings.Split(readFile("/proc/stat"), "\n") {
		if !strings.HasPrefix(line, "cpu ") {
			continue
		}
		values := parseUints(strings.Fields(line)[1:])
		for _, v := range values {
			total += v
		}
		// Fields: user nice system idle iowait ...; idle and iowait are not busy.
		if len(values) > 4 {
			return total - values[3] - values[4], total
		}
	}
	return 0, 0
}

func readMemory() Memory {
	info := map[string]uint64{}
	for _, line := range strings.Split(readFile("/proc/meminfo"), "\n") {
		fields := strings.Fields(line)
		if len(fields) >= 2 {
			value, _ := strconv.ParseUint(fields[1], 10, 64)
			info[strings.TrimSuffix(fields[0], ":")] = value * 1024
		}
	}
	memory := Memory{Total: info["MemTotal"], SwapTotal: info["SwapTotal"]}
	if info["MemAvailable"] <= memory.Total {
		memory.Used = memory.Total - info["MemAvailable"]
	}
	if info["SwapFree"] <= memory.SwapTotal {
		memory.SwapUsed = memory.SwapTotal - info["SwapFree"]
	}
	return memory
}

func readDisk(path string) Disk {
	var stat syscall.Statfs_t
	if err := syscall.Statfs(path, &stat); err != nil {
		return Disk{}
	}
	total := stat.Blocks * uint64(stat.Bsize)
	return Disk{Total: total, Used: total - stat.Bfree*uint64(stat.Bsize)}
}

func readDiskIO() (read, write uint64) {
	for _, line := range strings.Split(readFile("/proc/diskstats"), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 10 || !wholeDisk.MatchString(fields[2]) {
			continue
		}
		values := parseUints(fields[3:10])
		// Sectors read and written, always 512 bytes in this file.
		read += values[2] * 512
		write += values[6] * 512
	}
	return read, write
}

func readNetwork() (rx, tx uint64) {
	for _, line := range strings.Split(readFile("/proc/net/dev"), "\n") {
		name, rest, found := strings.Cut(line, ":")
		if !found || strings.TrimSpace(name) == "lo" {
			continue
		}
		fields := parseUints(strings.Fields(rest))
		if len(fields) >= 9 {
			rx += fields[0]
			tx += fields[8]
		}
	}
	return rx, tx
}

func countCores() int {
	count := 0
	for _, line := range strings.Split(readFile("/proc/cpuinfo"), "\n") {
		if strings.HasPrefix(line, "processor") {
			count++
		}
	}
	return count
}

func sample() counters {
	c := counters{at: time.Now()}
	c.cpuBusy, c.cpuTotal = readCPU()
	c.readBytes, c.writeBytes = readDiskIO()
	c.rxBytes, c.txBytes = readNetwork()
	return c
}

// perSecond returns the growth of a counter, treating a reset as zero.
func perSecond(now, before uint64, seconds float64) float64 {
	if now < before || seconds <= 0 {
		return 0
	}
	return float64(now-before) / seconds
}

func build(previous, current counters, interval int, diskPath string) Status {
	seconds := current.at.Sub(previous.at).Seconds()
	status := Status{
		GeneratedAt:  current.at.Unix(),
		IntervalSecs: interval,
		Cores:        countCores(),
		Memory:       readMemory(),
		Disk:         readDisk(diskPath),
		DiskIO:       Rate{perSecond(current.readBytes, previous.readBytes, seconds), perSecond(current.writeBytes, previous.writeBytes, seconds)},
		Network:      Rate{perSecond(current.rxBytes, previous.rxBytes, seconds), perSecond(current.txBytes, previous.txBytes, seconds)},
	}
	if current.cpuTotal > previous.cpuTotal && current.cpuBusy >= previous.cpuBusy {
		busy := float64(current.cpuBusy - previous.cpuBusy)
		status.CPUPercent = 100 * busy / float64(current.cpuTotal-previous.cpuTotal)
	}
	fields := strings.Fields(readFile("/proc/loadavg"))
	for i := 0; i < 3 && i < len(fields); i++ {
		status.Load[i], _ = strconv.ParseFloat(fields[i], 64)
	}
	if up := strings.Fields(readFile("/proc/uptime")); len(up) > 0 {
		uptime, _ := strconv.ParseFloat(up[0], 64)
		status.UptimeSeconds = int64(uptime)
	}
	return status
}

// writeStatus replaces the file atomically so readers never see partial JSON.
func writeStatus(path string, status Status) error {
	data, err := json.Marshal(status)
	if err != nil {
		return err
	}
	temp := path + ".tmp"
	if err := os.WriteFile(temp, data, 0o644); err != nil {
		return err
	}
	return os.Rename(temp, path)
}

func main() {
	output := flag.String("out", "/run/health/status.json", "status file to write")
	interval := flag.Int("interval", 5, "seconds between samples")
	diskPath := flag.String("disk", "/", "filesystem path to report disk usage for")
	flag.Parse()
	if *interval < 1 {
		log.Fatal("interval must be at least 1 second")
	}
	if err := os.MkdirAll(filepath.Dir(*output), 0o755); err != nil {
		log.Fatal(err)
	}

	previous := sample()
	ticker := time.NewTicker(time.Duration(*interval) * time.Second)
	defer ticker.Stop()
	for range ticker.C {
		current := sample()
		if err := writeStatus(*output, build(previous, current, *interval, *diskPath)); err != nil {
			log.Printf("write status: %v", err)
		}
		previous = current
	}
}
