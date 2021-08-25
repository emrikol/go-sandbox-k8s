#!/bin/bash
# Clone or download GH repo
git clone --recursive git@github.com:emrikol/go-sandbox-k8s.git ~/go-sandbox 2> /dev/null || git -C ~/go-sandbox pull

source ~/go-sandbox/bash_profile
