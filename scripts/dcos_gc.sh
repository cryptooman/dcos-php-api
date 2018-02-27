#!/bin/bash
# Various auto-rotated logs garbage collector
# Remove rotated logs after 14 days

LOG_PATHS=( \
/var/log/messages-* \
/run/log/journal/*/*.journal \
/var/log/journal/*/*.journal \
/var/lib/dcos/exhibitor/zookeeper/transactions/version-2/log.* \
)

echo 'Started at '`date '+%Y-%m-%d %H:%M:%S'`

for path in ${LOG_PATHS[@]}
do
    if [ "$(ls $path 2>/dev/null)" ]
    then
        # -cmin +20160 = 14 days
        find $path -cmin +20160 -type f -exec sh -c "rm -rf {} && echo 'Removed '{}" \;
    fi
done

echo 'Completed at '`date '+%Y-%m-%d %H:%M:%S'`
echo ''