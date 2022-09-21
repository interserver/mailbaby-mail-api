#!/bin/bash
git clone git@github.com:interserver/myadmin-mail-api.git
cp -f myadmin-mail-api/swagger.yaml public/openapi.yaml
rm -rf myadmin-mail-api
git pull --all
git commit public/openapi.yaml -m "Updated API Spec"
git push --all
git pull --all
