<?php

namespace Modules\Roles\Enums;

enum RolePanel: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case User = 'user';
}
