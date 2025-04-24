#!/bin/bash

# Build script for Modify Login plugin
# This creates a clean production-ready zip file for WordPress.org

# Set plugin name and version
PLUGIN_NAME="modify-login"
VERSION=$(grep "Version: " modify-login.php | sed 's/.*Version: \(.*\)/\1/')
echo "Building ${PLUGIN_NAME} version ${VERSION}"

# Create build directory if it doesn't exist
BUILD_DIR="./build"
if [ ! -d "$BUILD_DIR" ]; then
  mkdir -p "$BUILD_DIR"
fi

# Create a temporary directory for this build
TMP_DIR="${BUILD_DIR}/tmp"
rm -rf "${TMP_DIR}"
mkdir -p "${TMP_DIR}/${PLUGIN_NAME}"

# Copy all files to build directory
rsync -av --exclude-from="./.gitignore" --exclude="./build" --exclude="./.git" --exclude="./node_modules" ./ "${TMP_DIR}/${PLUGIN_NAME}/"

# Navigate to temporary directory
cd "${TMP_DIR}" || exit

# Create the zip file
echo "Creating zip file..."
zip -r "../${PLUGIN_NAME}-${VERSION}.zip" "./${PLUGIN_NAME}/" -x "*.DS_Store" "*.git*" "*node_modules*" "*__MACOSX*"

# Clean up
echo "Cleaning up..."
cd ../../ || exit
rm -rf "${TMP_DIR}"

echo "Build complete! Zip file is located at ${BUILD_DIR}/${PLUGIN_NAME}-${VERSION}.zip" 