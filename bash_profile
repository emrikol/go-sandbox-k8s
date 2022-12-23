#!/bin/bash

# Config git
if [ -f ~/.go-sandbox.json ]; then
	git config --global user.email "$(jq -r '.GIT_CONFIG_EMAIL' < ~/.go-sandbox.json)"
	git config --global user.name "$(jq -r '.GIT_CONFIG_USER' < ~/.go-sandbox.json)"
fi

# Some extra env vars.
VIPGO_HOSTNAME=$(hostname | sed 's/-sbx-.*$//g')
SANDBOXED_HOST=$(wp eval 'global $sandbox_vhosts; echo array_shift(array_values($sandbox_vhosts));' --skip-plugins --skip-themes  2> /dev/null)

# Move custom mu-plugins
yes | cp -af ~/go-sandbox/mu-plugins/* /var/www/wp-content/mu-plugins/

# Install rc files
yes | cp -af ~/go-sandbox/.nanorc ~/.nanorc

# Some simple aliases
alias logs="tail -F /tmp/php-errors -F /chroot/tmp/php-errors"
# No longer needed? Commenting out for now, may delete later.
#alias run-crons="wp core is-installed --network --path=/chroot/var/www 2> /dev/null && wp site list --path=/chroot/var/www --field=url 2> /dev/null | xargs -I URL bash -c 'echo Running cron for URL; wp --path=/chroot/var/www cron event run --due-now --url=URL 2> /dev/null' || echo Running cron for $(wp option get siteurl --path=/chroot/var/www 2> /dev/null); wp cron event run --due-now --path=/chroot/var/www 2> /dev/null"
alias ls="ls --color=auto"
alias wp="wp --require=/usr/local/vip-go-dev-scripts/wp-cli/wp-cli.php"

PS1="\
\[$(tput sgr0)\]\[\033[38;5;15m\]\[\033[48;5;124m\][S]\[$(tput sgr0)\]\[\033[38;5;124m\]\[$(tput sgr0)\]\[\033[38;5;15m\] \
\[$(tput sgr0)\]\[\033[38;5;6m\]\u\[$(tput sgr0)\]@\[\033[38;5;2m\]$VIPGO_HOSTNAME:\[$(tput sgr0)\]\[\033[38;5;3m\]\w\[$(tput sgr0)\]\[\033[38;5;15m\]\\$ \[$(tput sgr0)\]"
export PS1

# Path
export PATH=/root/bin/:/root/go-sandbox/bin/:$PATH
export LD_LIBRARY_PATH=/root/go-sandbox/bin/:$LD_LIBRARY_PATH

# Adds hostname title and badge to iTerm2
printf "\e]1337;SetBadgeFormat=%s\a" $(echo -n "VIP: $SANDBOXED_HOST" | base64)
echo -ne "\033]0;VIP: $SANDBOXED_HOST\007"

# Allows "local" tailing of PHP logs for debugging 😬
enable_local_php_logs() {
	grep -c "ini_set( 'error_log', '/tmp/php-errors' );" /var/www/vip-config/vip-config.php &> /dev/null
	ret=$?
	if [ $ret -ne 0 ]; then
		echo "ini_set( 'error_log', '/tmp/php-errors' );" >> /var/www/vip-config/vip-config.php
	fi
}
