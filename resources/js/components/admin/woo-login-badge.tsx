import { Store } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { AdminUser } from '@/types/admin';

/** Marks an ERP customer who signs in with their WooCommerce (website) login. */
export function WooLoginBadge({
    user,
}: {
    user: Pick<AdminUser, 'woo_username' | 'erp_contact_code'>;
}) {
    const username = user.woo_username || user.erp_contact_code;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Badge
                    variant="outline"
                    className="gap-1 border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-800 dark:bg-violet-900/20 dark:text-violet-300"
                >
                    <Store className="h-3 w-3" />
                    Woo
                </Badge>
            </TooltipTrigger>
            <TooltipContent>
                Signs in with their WooCommerce login
                {username ? ` (${username})` : ''}. The password can only be
                changed in the ERP.
            </TooltipContent>
        </Tooltip>
    );
}
