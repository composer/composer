# Copy to /etc/bash_completion.d/composer and restart your bash

_composer_search()
{
	composer search "^$1" 2>/dev/null | awk '{ print $1; }'
}

_composer()
{
	local cur prev opts commands isOpts
	COMPREPLY=()
	cur="${COMP_WORDS[COMP_CWORD]}"
	prev="${COMP_WORDS[COMP_CWORD-1]}"

	local base_opts="--help -h --quiet -q --verbose -v --version -V --ansi --no-ansi --no-interaction -n"
	local base_commands="about create-project depends help init install list search self-update show update validate"

	opts="${base_opts}"

	if [[ ${cur} == -* ]]; then
		isOpts=1
	else
		isOpts=0
	fi

	case "${prev}" in
	composer)
		commands="${base_commands}"
		;;

	about)
		# nothing, shouldn't default!
		;;

	create-project)
		opts="${opts} --prefer-source --repository-url"
		# commands - should autocomplete repos
		;;

	depends)
		opts="${opts} --link-type"
		if [ $isOpts == 0 ]; then
			commands=$(_composer_search $cur)
		fi
		;;

	help)
		commands="${base_commands}"
		;;

	init)
		opts="${opts} --name --description --author --homepage --require"
		# shouldn't default commands
		;;

	install)
		opts="${opts} --prefer-source --dry-run --dev"
		# shouldn't default commands
		;;

	list)
		opts="${opts} --xml --raw"
		# shouldn't default commands
		;;

	search)
		# nothing, shouldn't default!
		;;

	self-update)
		# nothing, shouldn't default!
		;;

	show)
		opts="${opts} --installed --platform"
		if [ $isOpts == 0 ]; then
			commands=$(_composer_search $cur)
		fi
		;;

	update)
		opts="${opts} --prefer-source --dry-run --dev"
		# shouldn't default commands
		;;

	validate)
		# nothing
		;;

	esac


	# Common part
	if [[ ${cur} == -* ]]; then
		COMPREPLY=($( compgen -W "${opts}" -- "${cur}" ))
	else
		COMPREPLY=($( compgen -W "${commands}" -- "${cur}" ))
	fi
	return 0

}

complete -o default -F _composer composer
