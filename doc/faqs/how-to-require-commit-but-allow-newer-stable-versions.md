# How to require a specific commit currently, but allow newer stable versions later, too?

Sometimes a newer commit might be availble in the repository with a fix you need, without a new stable version being released. 

So lets say there is a tagged release of `v12.4.4` and you are currently requiring `^12.4.1`, which installs `v12.4.4`.
But a later commit `fceda69` on the main branch was added, that fixes your problem.
You can require that commit via e.g. `dev-main#fceda69` however such a constraint would prevent future updates to stable versions.

So instead you could have a require of `^12.4.5 || dev-main#fceda69` so requiring (currently not available) next stable patch version or the specific commit. 
As long as no new version is released, still your specific commit will be the best match. As soon as a `v12.4.5` or a `v12.5.0` is released 
(on the same branch) the algorithm will detect it is allowed and newer as your specified commit, thus switch to that newer stable version instead.

This allows a solution without managing patch files. For similiar scenarios [composer-patches](https://github.com/cweagans/composer-patches) might be worth a look.
