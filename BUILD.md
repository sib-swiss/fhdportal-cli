# FHDportal CLI - Build and Distribution Guide

This document describes how to build and distribute the FHDportal CLI application as both PHAR archives and native platform binaries.

## Overview

FHDportal CLI supports two distribution formats:

1. **PHAR Distribution** - Cross-platform PHP archive (~2.5MB, requires PHP 8.2+)
2. **Native Binaries** - Platform-specific executables (~26-29MB each, self-contained)

## Build Process

The build pipeline uses modern tools:

- **[Box](https://github.com/box-project/box)** - Creates optimized PHAR files
- **[PHPacker](https://github.com/phpacker/phpacker)** - Converts PHAR to native platform binaries

## Prerequisites

Install the required build tools:

```bash
# Install Box for PHAR creation
composer global require humbug/box

# Install PHPacker for native binaries  
composer global require phpacker/phpacker
```

## Quick Build

Use the build script for all packaging needs:

```bash
# PHAR only (cross-platform, requires PHP)
./build.sh

# PHAR + Native binaries (recommended for distribution)
./build.sh --with-binaries

# Native binaries only (from existing PHAR)
./build.sh --binaries-only
```

## Manual Build

If you need to build manually:

```bash
# Create PHAR with Box
box compile

# Create native binaries with PHPacker (multi-architecture support)
phpacker build mac x64,arm64 --src fega.phar --dest dist/temp_mac
phpacker build linux x64,arm64 --src fega.phar --dest dist/temp_linux  
phpacker build windows x64 --src fega.phar --dest dist/temp_windows
```

## Output Files

After building, you'll find:

### PHAR
- **File**: `fega.phar` (~2.5MB compressed)
- **Requirements**: PHP 8.2+ with zlib extension
- **Platforms**: Any with PHP runtime

### Native Binaries
- **Files**: `dist/` directory
  - `fega-linux` (Linux x64)
  - `fega-macos-x64` (macOS Intel)
  - `fega-macos-arm` (macOS Apple Silicon)
  - `fega-windows.exe` (Windows x64)
- **Requirements**: None (self-contained)
- **Size**: ~26-29MB each

## Architecture Support

### Current Support
- **Linux**: x64
- **macOS**: x64 (Intel), arm (Apple Silicon) - Separate binaries
- **Windows**: x64

### Apple Silicon Support
**Yes** - macOS has separate binaries for Intel (`fega-macos-x64`) and Apple Silicon (`fega-macos-arm`) architectures.

## Configuration

### Build Configuration
- **Box config**: `box.json` - PHAR build settings
- **Stub file**: `bin/console.stub` - PHAR entry point
- **Build script**: `build.sh` - Unified build script

### Platform-Specific Data Storage

The application automatically uses appropriate directories:

**Schema Storage** (via `update` command):
- **Linux**: `~/.fega/schemas/`
- **macOS**: `~/Library/Application Support/.fega/schemas/`
- **Windows**: `%LOCALAPPDATA%\fega\schemas\`

**Cache Directory**:
- **Linux**: `/tmp/fega-cache/`
- **macOS**: `~/Library/Caches/fega/`
- **Windows**: `%TEMP%\fega-cache\`

## Usage Examples

Both PHAR and native binaries work identically:

```bash
# PHAR usage (requires PHP)
./fega.phar --help
./fega.phar validate data/package
./fega.phar update

# Native binary usage (no PHP required)
./dist/fega-linux --help
./dist/fega-macos-x64 validate data/package    # Intel Mac
./dist/fega-macos-arm validate data/package    # Apple Silicon Mac
./dist/fega-windows.exe template -o templates.zip
```

## Distribution Comparison

| Format | Size | Requirements | Pros | Cons |
|--------|------|--------------|------|------|
| **PHAR** | 2.5MB | PHP 8.2+ | Small, cross-platform | Needs PHP runtime |
| **Native** | 26-29MB | None | Self-contained, fast | Larger, platform-specific |

## File Structure

The PHAR includes:
- Source code (`src/`)
- Configuration (`config/`)
- Dependencies (`vendor/`)
- Build configuration (`box.json`, `bin/console.stub`)

## Docker Usage

FEGA CLI can be easily containerized using Docker.

### Building Docker Image

```bash
# Build from the included Dockerfile
docker build -t fega-cli .

# Verify the build
docker run --rm fega-cli php fega.phar --version
```

### Using Custom Schema Directory

The FEGA CLI respects the `FEGA_SCHEMA_DIR` environment variable for custom schema storage locations:

```bash
# Run with default schema location (/opt/fega/schemas)
docker run --rm fega-cli php fega.phar update

# Run with custom schema location
docker run --rm -e FEGA_SCHEMA_DIR=/custom/path \
  -v $(pwd)/schemas:/custom/path \
  fega-cli php fega.phar update

# Validate with persistent schemas
docker run --rm \
  -e FEGA_SCHEMA_DIR=/data/schemas \
  -v $(pwd)/schemas:/data/schemas \
  -v $(pwd)/metadata:/data/metadata \
  fega-cli php fega.phar validate /data/metadata
```

## Troubleshooting

### Build Issues

**PHAR creation fails:**
```bash
# Ensure phar.readonly is disabled
php -d phar.readonly=0 -r "echo 'PHAR creation enabled';"
```

**Commands not showing:**
```bash
# Clear the cache
./fega.phar cache:clear
```

**Permission errors:**
```bash
# Make executable on Unix systems
chmod +x fega.phar
chmod +x dist/fega-*
```

### Architecture Detection

To verify which architecture binary you have:
```bash
# Check file type
file dist/fega-macos-x64   # Should show: x86_64
file dist/fega-macos-arm   # Should show: arm64

# Test which one works on your system
./dist/fega-macos-x64 --version   # Works on Intel Macs
./dist/fega-macos-arm --version   # Works on Apple Silicon Macs
```
