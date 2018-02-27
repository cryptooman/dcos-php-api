#!/bin/bash
# Docker garbage collection

DRY_RUN=0
for arg in "$@"
do
case $arg in
    --dry-run=*)
    DRY_RUN="${arg#*=}"
    shift
    ;;
    *)
        echo "Unknown option [$arg]. Usage /bin/bash $0 [--dry-run=1]"
        exit 1
    ;;
esac
done

echo 'Started at '`date '+%Y-%m-%d %H:%M:%S'`

# Unmount unused (stuck) overlays

echo 'Unmount unused overlays'

# Get all mounted overlays (in case of new overlays will be mounted during the script processing these overlays will not be affected)
i=0
for overlay in `df -h | grep overlay | sed -r 's/^.+(\/var\/lib\/docker\/overlay\/.+)$/\1/'`
do
    mounted_overlays_path[$i]=$overlay
    mounted_overlays_hash[$i]=`echo $overlay | sed -r 's/\/var\/lib\/docker\/overlay\/([a-z0-9]+).+/\1/'`
    i=$((i+1))
done
echo 'Mounted overlays: '${#mounted_overlays_path[@]}

# Get overlays for active containers
active_containers=`docker ps -a -q -f status=running -f status=created -f status=restarting -f status=removing -f status=paused`
i=0
for container_id in $active_containers
do
    active_overlays_path[$i]=`docker inspect $container_id | grep 'MergedDir' | sed -r 's/.+(\/var\/lib\/docker\/overlay\/[^\/]+\/merged).+/\1/'`
    active_overlays_hash[$i]=`echo ${active_overlays_path[$i]} | sed -r 's/\/var\/lib\/docker\/overlay\/([a-z0-9]+).+/\1/'`
    i=$((i+1))
done
echo 'Active overlays: '${#active_overlays_path[@]}

# Get unused overlays
i=0
for m_hash in ${mounted_overlays_hash[@]}
do
    active=0
    for a_hash in ${active_overlays_hash[@]}
    do
        if [ $a_hash == $m_hash ]
        then
            active=1
        fi
    done

    if [ $active -eq 0 ]
    then
        unused_overlays_path[$i]=${mounted_overlays_path[$i]}
        unused_overlays_hash[$i]=${mounted_overlays_hash[$i]}
        i=$((i+1))
    fi
done
echo 'Unused overlays: '${#unused_overlays_path[@]}

if [ ${#unused_overlays_path[@]} -ge 1 ]
then
    # Unmount unused overlays
    for i in "${!unused_overlays_path[@]}"
    do
        echo 'Unmounting overlay: '${unused_overlays_hash[$i]}
        if [ $DRY_RUN -eq 0 ]
        then
            echo "exec: umount ${unused_overlays_path[$i]}"
            umount ${unused_overlays_path[$i]}
        else
            echo "dry-run: umount ${unused_overlays_path[$i]}"
        fi
    done
    echo 'Done'
fi

# Remove unused containers

echo 'Removing unused containers'
for container_id in `docker ps -a -q -f status=exited -f status=dead`
do
    if [ $DRY_RUN -eq 0 ]
    then
        echo "exec: docker rm $container_id"
        docker rm $container_id
    else
        echo "dry-run: docker rm $container_id"
    fi
done

# Remove dangling (untagged) images

echo 'Removing dangling images'
images=`docker images -qa -f dangling=true`
if [ "$images" ]
then
    if [ $DRY_RUN -eq 0 ]
    then
        echo "exec: docker rmi $images"
        docker rmi $images
    else
        echo "dry-run: docker rmi $images"
    fi
fi

# Remove unused layers
# NOTE: Be carefull when doing manual manipulation with /var/lib/docker/overlay/*, as it can break docker and require reinstall

echo 'Removing unused layers'
images=`docker images -qa`
if [ "$images" ]
then
    for layer in `ls /var/lib/docker/image/overlay/layerdb/sha256/`
    do
        image_layer=`cat /var/lib/docker/image/overlay/layerdb/sha256/$layer/diff`
        active=0
        for image_id in $images
        do
            docker inspect --format "{{ .RootFS.Layers }}" $image_id | tr ' ' "\n" | grep $image_layer 1>/dev/null
            if [ $? -eq 0 ]
            then
                active=1
                break
            fi
        done

        if [ $active -eq 0 ]
        then
            layer_path="/var/lib/docker/image/overlay/layerdb/sha256/$layer"
            root_overlay_hash=`cat $layer_path/cache-id`
            root_overlay_path="/var/lib/docker/overlay/$root_overlay_hash/root"
            echo "Removing layer: $image_layer -> $root_overlay_path"
            if [ $DRY_RUN -eq 0 ]
            then
                echo "exec: rm -rf $root_overlay_path && rm -rf $layer_path"
                rm -rf $root_overlay_path && rm -rf $layer_path
            else
                echo "dry-run: rm -rf $root_overlay_path && rm -rf $layer_path"
            fi
        fi
    done
fi

# Remove container's old logs

LOGS_LIFETIME_MINUTES=$((1440*3)) # 3 days
echo "Removing container's old logs"
if [ "$(ls /var/lib/docker/containers/*/*-json.log 2>/dev/null)" ]
then
    if [ $DRY_RUN -eq 0 ]
    then
        echo "exec: find /var/lib/docker/containers/*/*-json.log -cmin +$LOGS_LIFETIME_MINUTES -exec sh -c \"rm -rf {} && echo 'Removed '{}\" \;"
        find /var/lib/docker/containers/*/*-json.log -cmin +$LOGS_LIFETIME_MINUTES -exec sh -c "rm -rf {} && echo 'Removed '{}" \;
    else
        echo "dry-run: find /var/lib/docker/containers/*/*-json.log -cmin +$LOGS_LIFETIME_MINUTES -exec sh -c \"rm -rf {} && echo 'Removed '{}\" \;"
    fi
fi

echo 'Completed at '`date '+%Y-%m-%d %H:%M:%S'`
echo ''
