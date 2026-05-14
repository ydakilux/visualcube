# VisualCube
Generate custom Rubik's cube visualisations from your browser address bar. 

### Installation Instructions

These instructions are for installing the script on your own web server. If you do not have access to your own server, or would just like to try out the software, please visit:
http://cube.rider.biz/visualcube.php

#### Using Docker

```
docker build -t visualcube .
docker run -p 80:80 --rm visualcube
```

##### Prerequisites

* Access to an Apache web server with PHP and ImageMagic installed.
* A MySQL database. This is optional, but will improve performance if you have a high traffic website.

##### Steps

1. Download and extract the code into a web-accessible folder.
2. Edit the configuration variables in visualcube_config.php
3. Point your browser to: www.yourwebsite.com/visualcube.php
4. (Optional) Edit DB_USER, DB_PASS and DB_NAME in visualcube_dbprune.sh and install the cron job.
5. (Optional) Configure mod_rewrite to redirect image suffixes to corresponding fmt=xxx form. See below.

##### Configuring mod_rewrite
Add a .htaccess file to the same folder as visualcube.php with something like the following:
```
RewriteEngine On

RewriteCond %{HTTP_HOST} ^(www\.example\.com)$
RewriteRule ^visualcube\.(gif|png|jpg|jpeg|tiff|ico)$ http://www.example.com/visualcube.php?%{QUERY_STRING}&fmt=$1 [L]
```


### Features

* Fully 3D cube visualisation
* Cube dimensions from 1x1x1 to NxNxN. Currently capped at 9x9 for performance.
* Algorithm support
* Complete orientation control
* Multiple image formats
* Custom image size
* Cube and facelet transparency
* Custom colour schemes and background
* Image caching for speedy access
* Cookie configurable variables
* Arrow overlays
* Highly configurable URL-based API
* Open Source

### New Options

#### Glass-style move arrows (`arw=`)

Every standard cube notation move now renders a custom, hand-tuned "glass" arrow:
a thick light-grey ribbon with a black outline and a white gloss highlight,
shaped by 4 sticker anchors and drawn with a centripetal Catmull-Rom spline.

Supported moves (append `'` for counter-clockwise, `2` for a double turn):

| Group         | Moves                       |
| ------------- | --------------------------- |
| Face moves    | `U`, `R`, `L`, `F`, `D`, `B` |
| Cube rotations| `x`, `y`, `z`               |
| Slice moves   | `M`, `E`, `S`               |

Example:
```
visualcube.php?fmt=png&size=400&bg=t&arw=R,U,R',U'
visualcube.php?fmt=png&size=400&bg=t&arw=x
visualcube.php?fmt=png&size=400&bg=t&arw=M,E,S
```

Arrow thickness is computed from the U-face diagonal, so the look stays
consistent across cube sizes and view angles. URLs containing apostrophes
should percent-encode them as `%27`.

#### Numbered sticker overlay (`numbers=`)

Adds a small white disc on each sticker with a face letter and a 1-based
sticker number, useful for picking anchor positions or describing
algorithms visually.

| Value         | Effect                                                |
| ------------- | ----------------------------------------------------- |
| `numbers=1`   | Number all visible faces                              |
| `numbers=UFR` | Number only the listed faces (any subset of `UFRDLB`) |
| `numbers=0`   | Disable (default)                                     |

`numbered=...` is accepted as a legacy alias.

Example:
```
visualcube.php?fmt=png&size=400&bg=t&numbers=UFR
visualcube.php?fmt=png&size=400&bg=t&numbers=1&arw=R
```

#### Notation overview helper

A small Python script under `output/_make_notation_grid.py` (with a
Windows wrapper `create notatation grid.bat`) calls the local
`visualcube.php` once per move and stitches the results into a single
3x6 overview image of every supported move arrow. Handy for quickly
reviewing all arrows at once after styling changes.
