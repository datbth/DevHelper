#!/bin/bash

set -e

cd /var/www/html/

{ \
    echo "# Generated by $0"; \
    find addons/ -maxdepth 5 -name .git -type d | sed 's#/.git##'; \
} > ./xenforo/internal_data/addons2.txt
