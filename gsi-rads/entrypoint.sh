#!/bin/bash

/usr/bin/supervisord -c /etc/supervisord.conf
supervisorctl -c /etc/supervisord.conf start celery-worker:*
cd /src && python app.py & tail -f /var/log/celery-worker.log
