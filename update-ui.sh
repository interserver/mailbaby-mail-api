#!/bin/bash
git clone https://github.com/swagger-api/swagger-ui
cp -a swagger-ui/dist public/ui
rm -rf swagger-ui

