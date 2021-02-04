<?php

enum RolesClassLikeEnum: string implements TestFoo {
    case Admin = 'Administrator';
    case Guest = 'Guest';
    case Moderator = 'Moderator';
}
