# -*- mode: ruby -*-
# vi: set ft=ruby :

Vagrant.configure(2) do |config|

  # Define which vagrant box to use.
  config.vm.box = "ubuntu/trusty64"

  # Configure the VM as necessary so that the tests can pass.
  config.vm.provision :shell, :path => "vagrant-provision.sh"
end
