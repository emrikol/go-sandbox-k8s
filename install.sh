#!/bin/bash

# Function to add keys to known_hosts if not exists.
add_github_ssh_keys() {
	KEYS=(
		"github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"
		"github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg="
		"github.com ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQCj7ndNxQowgcQnjshcLrqPEiiphnt+VTTvDP6mHBL9j1aNUkY4Ue1gvwnGLVlOhGeYrnZaMgRK6+PKCUXaDbC7qtbW8gIkhL7aGCsOr/C56SJMy/BCZfxd1nWzAOxSDPgVsmerOBYfNqltV9/hWCqBywINIR+5dIg6JTJ72pcEpEjcYgXkE2YEFXV1JHnsKgbLWNlhScqb2UmyRkQyytRLtL+38TGxkxCflmO+5Z8CSSNY7GidjMIZ7Q4zMjA2n1nGrlTDkzwDCsw+wqFPGQA179cnfGWOWRVruj16z6XyvxvjJwbz0wQZ75XK5tKSb7FNyeIEs4TT4jk+S4dhPeAUC5y+bDYirYgM4GC7uEnztnZyaVWQ7B381AK4Qdrwt51ZqExKbQpTUNn+EjqoTwvqNj4kqx5QUCI0ThS/YkOxJCXmPUWZbhjpCg56i+2aB6CmK2JGhn57K5mj0MNdBXA4/WnwH6XoPWJzK5Nyu2zB3nAZp+S5hpQs+p1vN1/wsjk="
	)

	KNOWN_HOSTS_FILE="$HOME/.ssh/known_hosts"

	for key in "${KEYS[@]}"; do
		if ! grep -qF "$key" "$KNOWN_HOSTS_FILE"; then
			echo "$key" >> "$KNOWN_HOSTS_FILE"
			echo "Added key to $KNOWN_HOSTS_FILE: $key"
		else
			echo "Key already exists in $KNOWN_HOSTS_FILE: $key"
		fi
	done
}

# Install GitHub's SSH keys to known_hosts: https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints
add_github_ssh_keys

# Clone or download GH repo
git clone --recursive git@github.com:emrikol/go-sandbox-k8s.git ~/go-sandbox 2> /dev/null || git -C ~/go-sandbox pull

# Add source to bashrc if not exists
grep -c source ~/.bash_profile &> /dev/null
ret=$?
if [ $ret -ne 0 ]; then
	echo "source ~/go-sandbox/bash_profile" >> ~/.bash_profile
fi
