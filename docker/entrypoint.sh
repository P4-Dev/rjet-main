#!/usr/bin/env bash
set -e

# Remova qualquer chmod em tempo de execução, pois ao rodar como root não é necessário
# e, se rodar como usuário comum, geraria erro "Operation not permitted".

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
