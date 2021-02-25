<?php

namespace Foo\Bar;

enum RolesClassLikeNamespacedEnum: string implements TestFoo {
    case Admin = 'Administrator';
    case Guest = 'Guest';
    case Moderator = 'Moderator';
}
