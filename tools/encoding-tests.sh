#!/bin/sh

# Copying and distribution of this file, with or without modification,
# are permitted in any medium without royalty provided this notice is
# preserved.  This file is offered as-is, without any warranty.
# Names of contributors must not be used to endorse or promote products
# derived from this file without specific prior written permission.


EXIT_FAILURE=1
exit_code=0
cmd='file --brief --mime-encoding'

for file_or_dir in "$@"
do
    if test ! -e "$file_or_dir"
    then
	>&2 echo "$file_or_dir does not exist!"
	exit $EXIT_FAILURE
    else
	files=$(find "$file_or_dir" \( \
		-name '*.h'  -o -name '*.c' -o \
		-name '*.hh' -o -name '*.hpp' -o -name '*.hxx' -o \
		-name '*.cc' -o -name '*.cpp' -o -name '*.cxx' -o \
		-name '*.java' -o -name '*.js' -o -name '*.php' -o \
		-name '*.xml' -o -name '*.xsd' -o -name '*.svg' -o \
		-name '*.xsl' -o -name '*.xslt' -o \
		-name '*.json' -o -name '*.yml' -o \
		-name '*.htm' -o -name '*.html' -o -name '*.xhtml' -o \
		-name '*.css' -o \
		-name '*.txt' -o -name '*.md' -o -name '*.mkd' -o \
		-name '.*config' -o -name '*.el' -o \
		-iname Doxyfile -o -iname makefile \))
	for file in $files
	do
	    is_valid=$(echo "$file" | \
			      grep -e '*.ico' -e '*.png' -e '*.jpg' -e '*.jpeg' -e '*.gif' \
			      | wc -l)
	    if test $is_valid = 0
	    then
		nb_invalid=$($cmd "$file" | \
				    grep -ivc 'ascii\|utf-8' | \
				    tr -d '\r\n')
		if test "$nb_invalid" != '0'
		then
		    >&2 echo "$file"
		    exit_code=$EXIT_FAILURE
		fi
	    fi
	done
    fi
done

exit $exit_code
