#/bin/bash

shard=$1
#echo $shard

for i in {1..7}
do
       ipad=`printf %02d $i`
#        echo "pri$ipad " `ssh pri$ipad.gt 'df -h /ssd | grep ssd | grep G'`
        full_shard=$shard$ipad.gt
#       echo $full_shard
	cmd='if [ -n "` mongo --port 27018 billing --eval \"tojson(rs.status());\" | grep ARBITER `" ]; then mongo --port 27018 billing --eval "rs.stepDown();"; fi' 
        ssh $full_shard $cmd
done 
