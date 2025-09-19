#!/usr/bin/env bash
set -euo pipefail
# Create least-privilege user & isolated vhost
docker exec -it pie-rabbit bash -lc '
rabbitmqctl add_vhost /pie || true
rabbitmqctl add_user pie_user pie_pass || true
rabbitmqctl set_permissions -p /pie pie_user "^payout\\.direct$" "^payout\\.direct$" ".*"
rabbitmqctl set_topic_permissions -p /pie pie_user "" "" "" || true
rabbitmqctl set_user_tags pie_user monitoring
'
echo "RabbitMQ vhost /pie and user pie_user initialized."
