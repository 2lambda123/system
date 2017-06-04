#!/bin/bash

###  script arguments as follow:
###  1) Path to the billrun installation directory
###  2) Environment (dev / prod etc.)

if [ $1 ]; then
	cd $1;
	export APPLICATION_MULTITENANT=1;
	INDEX=public/index.php

else
	echo "please supply the path to the billrun installation directory"
	exit 1
fi

if [ -z "$APPLICATION_MULTITENANT" ] || [ "$APPLICATION_MULTITENANT" != 1 ]; then
    echo "Not running in multi-tenant mode. Exiting."
    exit 1
fi  

if [ $2 ]; then
	ENV=$2
else
	echo "please supply an environment"
	exit 2
fi

CLIENTS=$1/conf/tenants/*.ini
LOCKS=$1/scripts/locks/
CYCLE_SCRIPT=$1/scripts/pseudo_cron_cycle.sh

# Go through the files
for f in $CLIENTS
do
	if [ "$CLIENTS" != "$f" ]; then # non-empty folder check
		TEMP=$(basename $f)
		TENANT=${TEMP%.*}
		if [ "$TENANT" == "base" ]; then
			continue
		fi

		echo "Running calculators for "$TENANT" file"
		flock -n $LOCKS"/pseudo_cron_"$TENANT".lock" -c "sh $CYCLE_SCRIPT $TENANT $ENV $INDEX"
	else
		echo "No tenants found"
	fi	
done