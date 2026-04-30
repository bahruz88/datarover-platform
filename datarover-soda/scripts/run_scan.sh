#!/bin/bash
#
# DataRover Soda Scanner Script
# Usage: ./run_scan.sh [data_source] [check_path]
#

set -e

# Configuration
CONFIG_DIR="config"
RESULTS_DIR="results"
LOG_DIR="logs"
DATA_SOURCE="${1:-postgres_db}"
CHECK_PATH="${2:-checks/}"

# Create directories
mkdir -p "$RESULTS_DIR/history" "$LOG_DIR"

# Timestamp
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
LOG_FILE="$LOG_DIR/soda_${TIMESTAMP}.log"

echo "=============================================="
echo "DataRover Soda Scanner"
echo "=============================================="
echo "Data Source: $DATA_SOURCE"
echo "Check Path:  $CHECK_PATH"
echo "Timestamp:   $TIMESTAMP"
echo "=============================================="

# Check if soda is installed
if ! command -v soda &> /dev/null; then
    echo "Error: soda-core is not installed"
    echo "Run: pip install soda-core-postgres soda-core-mysql"
    exit 1
fi

# Find configuration file
CONFIG_FILE=""
if [ -f "$CONFIG_DIR/configuration.yml" ]; then
    CONFIG_FILE="$CONFIG_DIR/configuration.yml"
elif [ -f "$CONFIG_DIR/${DATA_SOURCE%%_*}_connection.yml" ]; then
    CONFIG_FILE="$CONFIG_DIR/${DATA_SOURCE%%_*}_connection.yml"
else
    echo "Error: No configuration file found for $DATA_SOURCE"
    exit 1
fi

echo "Config File: $CONFIG_FILE"
echo "=============================================="
echo ""

# Run scan
echo "Starting scan..."
soda scan \
    -d "$DATA_SOURCE" \
    -c "$CONFIG_FILE" \
    "$CHECK_PATH" \
    -V 2>&1 | tee "$LOG_FILE"

# Check exit status
SCAN_STATUS=$?

echo ""
echo "=============================================="

if [ $SCAN_STATUS -eq 0 ]; then
    echo "✅ Scan completed successfully"
elif [ $SCAN_STATUS -eq 1 ]; then
    echo "⚠️  Scan completed with warnings"
elif [ $SCAN_STATUS -eq 2 ]; then
    echo "❌ Scan completed with failures"
else
    echo "💥 Scan failed with errors"
fi

echo "=============================================="
echo "Log saved to: $LOG_FILE"
echo ""

exit $SCAN_STATUS
