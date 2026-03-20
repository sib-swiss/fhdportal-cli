# FEGA CLI - Dockerfile
#
# Build:
#   docker build -f Dockerfile -t fega-cli .
#
# Run:
#   docker run --rm fega-cli php fega.phar --version
#   docker run --rm fega-cli php fega.phar update
#   docker run --rm -v $(pwd)/data:/data fega-cli php fega.phar validate /data
#

# Pin to a specific patch version to prevent silent base-image updates
FROM php:8.4.5-cli-alpine

# Install required dependencies and PHP extensions
RUN apk add --no-cache libzip-dev libzip && \
    docker-php-ext-install zip

# Set custom schema directory (can be overridden at runtime)
# This environment variable tells FEGA CLI where to store/read schemas
ENV FEGA_SCHEMA_DIR=/opt/fega/schemas

# Create schema directory with appropriate permissions
RUN mkdir -p ${FEGA_SCHEMA_DIR} && \
    chmod -R 755 /opt/fega

# Set working directory
WORKDIR /app

# Copy the FEGA CLI PHAR
COPY fega.phar /app/fega.phar

# Make PHAR executable
RUN chmod +x /app/fega.phar

# Verify installation
RUN php /app/fega.phar --version

# Create a non-root user
RUN addgroup -S fega && adduser -S fega -G fega

# Set correct ownership
RUN chown -R fega:fega /app /opt/fega

# Drop privileges
USER fega

# Default command: show help
CMD ["php", "/app/fega.phar", "--help"]
