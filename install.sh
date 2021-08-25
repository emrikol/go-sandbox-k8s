#!/bin/bash
# Clone or download GH repo
git clone --recursive git@github.com:emrikol/go-sandbox-k8s.git ~/go-sandbox 2> /dev/null || git -C ~/go-sandbox pull

# Add source to bashrc if not exists
grep -c source ~/.bashrc &> /dev/null
ret=$?
if [ $ret -ne 0 ]; then
	echo "source ~/go-sandbox/bash_profile" >> ~/.bashrc
fi
