FROM php:7.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends imagemagick librsvg2-bin \
    && rm -rf /var/lib/apt/lists/*

# Install a convert wrapper that saves SVG stdin to a real temp file before
# calling ImageMagick, bypassing the rsvg-convert symlink-to-stdin limitation.
COPY convert-vc.sh /usr/local/bin/convert-vc
RUN chmod +x /usr/local/bin/convert-vc

COPY . /var/www/html/

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/visualcube.php?fmt=svg || exit 1
