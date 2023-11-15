#!/bin/bash

# Config git
if [ -f ~/.go-sandbox.json ]; then
	git config --global user.email "$(jq -r '.GIT_CONFIG_EMAIL' < ~/.go-sandbox.json)"
	git config --global user.name "$(jq -r '.GIT_CONFIG_USER' < ~/.go-sandbox.json)"
fi

# Some extra env vars.
VIPGO_HOSTNAME=$(hostname | sed 's/-sbx-.*$//g')
SANDBOXED_HOST=$(wp eval 'global $sandbox_vhosts; echo array_shift(array_values($sandbox_vhosts));' --skip-plugins --skip-themes  2> /dev/null)

# If $SANDBOXED_HOST is empty or only contains whitespace, set to $VIPGO_HOSTNAME
if [[ -z "${SANDBOXED_HOST// }" ]]; then
	SANDBOXED_HOST="$VIPGO_HOSTNAME"
fi

# Move custom mu-plugins if they do not exist
if [[ ! -f /var/www/wp-content/mu-plugins/00-sandbox-helper.php ]]; then
	yes | cp -af ~/go-sandbox/mu-plugins/* /var/www/wp-content/mu-plugins/
fi

# Install rc files
yes | cp -af ~/go-sandbox/.nanorc ~/.nanorc

# Some simple aliases
alias logs_long="enable_local_php_logs; tail -F /tmp/php-errors -F /chroot/tmp/php-errors"
alias logs='enable_local_php_logs; tail -F /tmp/php-errors -F /chroot/tmp/php-errors | cut -b -1000'
alias ls="ls --color=auto"
alias wp="wp --require=/usr/local/vip-go-dev-scripts/wp-cli/wp-cli.php"

PS1="\
\[$(tput sgr0)\]\[\033[38;5;15m\]\[\033[48;5;124m\][S]\[$(tput sgr0)\]\[\033[38;5;124m\]\[$(tput sgr0)\]\[\033[38;5;15m\] \
\[$(tput sgr0)\]\[\033[38;5;6m\]\u\[$(tput sgr0)\]@\[\033[38;5;2m\]$SANDBOXED_HOST:\[$(tput sgr0)\]\[\033[38;5;3m\]\w\[$(tput sgr0)\]\[\033[38;5;15m\]\\$ \[$(tput sgr0)\]"
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

# pbcopy via iTerm2
function pbcopy() {
	if which pbcopy >/dev/null 2>&1; then
		pbcopy "$@"
	else
		# Replace ^[ with the ASCII escape character
		local start="\e]1337;CopyToClipboard\a"
		local end="\e]1337;EndCopy\a"
		printf "${start}$(cat)${end}"
	fi
}

# Check if the line 'source $HOME/.bash_profile' already exists in $HOME/.bashrc
if ! grep -Fxq "source $HOME/.bash_profile" "$HOME/.bashrc"
then
    # If the line doesn't exist, append it to $HOME/.bashrc
    echo "source $HOME/.bash_profile" >> "$HOME/.bashrc"
fi
