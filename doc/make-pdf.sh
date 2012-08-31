#!/bin/bash

# Generate PDF from composer documentation.
# dependencies:
# * pandoc
# * latex

for file in $(find . -type f -name '*.md')
do
    pandoc -o $(dirname $file)/$(basename $file .md).tex $file
done

> book.tex
cat >> book.tex <<EOF
\documentclass[letterpaper]{book}

\title{Composer}
\author{The Composer Community}

\usepackage[letterpaper,margin=1.5in]{geometry}
\usepackage{hyperref}
\usepackage{url}
\usepackage{enumerate}
\usepackage{listings}
\usepackage{microtype}
\usepackage[htt]{hyphenat}
\usepackage[utf8]{inputenc}
\usepackage[T1]{fontenc}
\usepackage{textcomp}
\usepackage{tgpagella}

\lstset{
    breaklines=true,
    basicstyle=\ttfamily
}

\raggedbottom

\begin{document}

\setlength{\parindent}{0cm}
\setlength{\parskip}{0.1cm}

\maketitle
\tableofcontents

\setlength{\parskip}{0.4cm}
EOF

cat *.tex >> book.tex

# apply only to main part of book
sed -i.bak 's/\\section{/\\chapter{/g' book.tex
sed -i.bak 's/\\subsection{/\\section{/g' book.tex
sed -i.bak 's/\\subsubsection{/\\subsection{/g' book.tex
sed -i.bak '/←/d' book.tex
sed -i.bak '/→/d' book.tex
sed -i.bak 's/\\chapter{composer.json}/\\chapter[Schema]{composer.json}/g' book.tex

echo "\chapter{Articles}" >> book.tex
cat articles/*.tex >> book.tex
echo "\chapter{FAQs}" >> book.tex
cat faqs/*.tex >> book.tex
echo >> book.tex
echo "\end{document}" >> book.tex

# apply to whole book
sed -i.bak 's/\\begin{verbatim}/\\begin{minipage}{\\textwidth} \\begin{lstlisting}/g' book.tex
sed -i.bak 's/\\end{verbatim}/\\end{lstlisting} \\end{minipage}/g' book.tex
rm book.tex.bak

pdflatex book.tex
pdflatex book.tex

rm *.tex articles/*.tex dev/*.tex faqs/*.tex
rm book.{aux,log,out,toc}
