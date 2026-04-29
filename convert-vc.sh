#!/bin/sh
# Wrapper around ImageMagick convert to handle svg:- (SVG from stdin).
# rsvg-convert cannot read ImageMagick's symlink-to-stdin temp files,
# so we intercept the pipe, save SVG to a real temp file, and convert from it.

ARGS="$@"
TMPSVG=""

# Check if arguments include "svg:-" (reading SVG from stdin)
case "$ARGS" in
    *"svg:-"*)
        TMPSVG="/tmp/vc_svg_$$.svg"
        # Read stdin into a real temp file
        cat > "$TMPSVG"
        # Replace "svg:-" with the real file path in arguments
        NEWARGS=$(echo "$ARGS" | sed "s|svg:-|svg:$TMPSVG|g")
        /usr/bin/convert $NEWARGS
        STATUS=$?
        rm -f "$TMPSVG"
        exit $STATUS
        ;;
    *)
        /usr/bin/convert "$@"
        ;;
esac
