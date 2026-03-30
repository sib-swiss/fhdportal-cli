#!/bin/bash

# FHDportal CLI Build Script
# Builds PHAR (with Box) and platform binaries (with PHPacker)

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$SCRIPT_DIR"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if Box is installed
check_box() {
    if ! command -v box &>/dev/null; then
        error "Box not found. Install it with:"
        echo "  composer global require humbug/box"
        exit 1
    fi
    log "Box found: $(box --version)"
}

# Check if PHPacker is installed (optional)
check_phpacker() {
    if ! command -v phpacker &>/dev/null; then
        warn "PHPacker not found. Install it with:"
        echo "  composer global require phpacker/phpacker"
        return 1
    fi
    log "PHPacker found: $(phpacker --version)"
    return 0
}

# Build PHAR (with Box)
build_phar() {
    log "Building PHAR with Box..."
    
    # Remove existing PHAR
    if [ -f "fega.phar" ]; then
        rm fega.phar
        log "Removed existing PHAR"
    fi

    # Install without dev dependencies
    log "Installing production dependencies only..."
    composer install --no-dev --optimize-autoloader --no-interaction

    # Build with Box
    box compile
    
    # Test the PHAR
    if ./fega.phar --version >/dev/null 2>&1; then
        log "✓ PHAR created successfully!"
        log "File size: $(du -h fega.phar | cut -f1)"
    else
        error "✗ PHAR test failed!"
        composer install --no-interaction
        exit 1
    fi

    # Restore dev dependencies for local development
    log "Restoring development dependencies..."
    composer install --no-interaction
}

# Build platform binaries (with PHPacker)
build_binaries() {
    if ! check_phpacker; then
        warn "Skipping binary creation"
        return
    fi
    
    log "Creating platform binaries with PHPacker..."
    
    # Create binaries directory
    mkdir -p dist
    
    # Build for each platform with supported architectures
    platforms=("linux:fega-linux:x64" "mac:fega-macos:x64,arm" "windows:fega-windows.exe:x64")
    
    for platform_spec in "${platforms[@]}"; do
        platform="${platform_spec%%:*}"
        rest="${platform_spec#*:}"
        output_name="${rest%%:*}"
        architectures="${rest##*:}"
        
        log "Building for $platform (architectures: $architectures)..."
        
        # Use a temporary directory name
        temp_dir="dist/temp_$platform"
        
        # Convert comma-separated architectures to space-separated for phpacker
        arch_args=$(echo "$architectures" | tr ',' ' ')
        
        # shellcheck disable=SC2086
        phpacker build "$platform" $arch_args \
        --src fega.phar \
        --dest "$temp_dir" \
        --php 8.2 \
        --no-interaction
        
        # PHPacker creates nested directories, find the actual binaries
        if [[ "$platform" == "mac" ]] && [[ "$architectures" == *","* ]]; then
            # For multi-arch Mac builds, create separate binaries
            for arch in $(echo "$architectures" | tr ',' ' '); do
                arch_file=$(find "$temp_dir" -type f -name "*$arch*" 2>/dev/null | head -1)
                if [ -n "$arch_file" ] && [ -f "$arch_file" ]; then
                    arch_output="${output_name%.*}-$arch"
                    if [[ "$output_name" == *.* ]]; then
                        arch_output="${arch_output}.${output_name##*.}"
                    fi
                    mv "$arch_file" "dist/$arch_output"
                    log "✓ Created dist/$arch_output ($(du -h "dist/$arch_output" | cut -f1))"
                fi
            done
            # Clean up the temporary directory
            rm -rf "$temp_dir"
        else
            # Single architecture or non-Mac platform
            binary_file=$(find "$temp_dir" -type f -name "*$platform*" 2>/dev/null | head -1)
            if [ -n "$binary_file" ] && [ -f "$binary_file" ]; then
                # Move binary to the correct location
                mv "$binary_file" "dist/$output_name"
                # Clean up the temporary directory
                rm -rf "$temp_dir"
                log "✓ Created dist/$output_name ($(du -h "dist/$output_name" | cut -f1))"
            else
                error "✗ Failed to create $platform binary"
                log "Debug: Looking for files in $temp_dir"
                find "$temp_dir" -type f 2>/dev/null || true
            fi
        fi
    done
}

# Show usage
show_usage() {
    echo "FHDportal CLI Build Script"
    echo ""
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --phar-only    Build only PHAR file (default)"
    echo "  --with-binaries Build PHAR + platform binaries"
    echo "  --binaries-only Build only platform binaries (requires existing PHAR)"
    echo "  --help         Show this help"
    echo ""
    echo "Examples:"
    echo "  $0                    # Build PHAR only"
    echo "  $0 --with-binaries    # Build PHAR + binaries"
    echo "  $0 --binaries-only    # Build binaries from existing PHAR"
}

# Main script
main() {
    cd "$PROJECT_DIR"
    
    case "${1:-}" in
        --help)
            show_usage
            exit 0
        ;;
        --phar-only | "")
            check_box
            build_phar
        ;;
        --with-binaries)
            check_box
            build_phar
            build_binaries
        ;;
        --binaries-only)
            if [ ! -f "fega.phar" ]; then
                error "fega.phar not found. Run with --phar-only first."
                exit 1
            fi
            build_binaries
        ;;
        *)
            error "Unknown option: $1"
            show_usage
            exit 1
        ;;
    esac
    
    log "Build complete!"
}

main "$@"
