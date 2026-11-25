<?php

// Static-analysis stubs only (phpstan scanFiles): the shapes of larena/admin and larena/access that the licensing
// provider uses when those packages are in the site. Never loaded at runtime.

namespace Larena\Admin\Alerts {
    final class AdminAlerts
    {
        public function register(string $name, \Larena\Admin\Contracts\AdminAlertSource $source): void
        {
        }
    }

    enum AdminAlertLevel: string
    {
        case Danger = 'danger';
        case Warning = 'warning';
        case Info = 'info';
    }

    final class AdminAlert
    {
        public function __construct(
            public string $id,
            public AdminAlertLevel $level,
            public string $text,
            public ?string $source = null,
            public ?\DateTimeImmutable $at = null,
            public ?object $action = null,
            public ?object $acknowledge = null,
            public bool $pinned = false,
        ) {
        }
    }
}

namespace Larena\Admin\Contracts {
    interface AdminAlertSource
    {
        /** @return list<\Larena\Admin\Alerts\AdminAlert> */
        public function alerts(\Illuminate\Http\Request $request): array;
    }
}

namespace Larena\Access\Runtime {
    final class AccessOperationAuthorization
    {
        public function isAllowed(): bool
        {
            return false;
        }
    }

    final class AccessOperationAuthorizer
    {
        public function authorize(\Illuminate\Http\Request $request, string $operation, bool $auditDenial = false): AccessOperationAuthorization
        {
            return new AccessOperationAuthorization();
        }
    }
}
