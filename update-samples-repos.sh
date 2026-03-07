#!/bin/bash
IFS="
"
base="/home/sites/mailbaby-mail-api";
for l in php perl python; do
  cd $base;
  if [ ! -e mailbaby-client-${l} ]; then
    git clone https://github.com/interserver/mailbaby-client-${l}
    cd mailbaby-client-${l}
  else
    cd mailbaby-client-${l}
    git pull --all
  fi
  rm -rfv $(find .  -mindepth 1 -maxdepth 1 ! -name .git ! -name .whitesource)
  cp -av $(find ../mailbaby-api-samples/openapi-client/${l} -mindepth 1 -maxdepth 1) .
  if [ "$(git status|grep deleted:)" != "" ]; then
    git rm $(git status | grep deleted: | awk '{ print $2 }')
  fi
  if [ "$(git status|grep modified:)" != "" ]; then
    git add $(git status | grep modified: | awk '{ print $2 }')
  fi
  git add -A
  git commit -a -m "Updated ${l} API Client"
  git push
done
