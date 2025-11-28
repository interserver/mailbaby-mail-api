#!/bin/bash
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"  # This loads nvm
nvm use 20
cd /home/sites/mailbaby-mail-api/mailbaby-api-samples
start=$PWD;
for i in $(find $PWD|grep pom.xml); do
  cd $(dirname $i);
  pwd;
#  mvn versions:use-latest-versions
#  mvn -DnvdApiKey=$NVD_API_KEY org.owasp:dependency-check-maven:check
  rm -f pom.xml.versionsBackup target
  cd $start;
done
for i in $(find $PWD|grep package.json); do
  cd $(dirname $i);
  pwd;
  npm i
  npm update
  npm audit fix --force
  rm -rf node_modules
  cd $start;
done
for i in $(find $PWD|grep composer.json); do
  cd $(dirname $i);
  pwd;
  composer update -W -o -v --dev
  rm -rf vendor
  cd $start;
done
