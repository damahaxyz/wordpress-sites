<?php
declare(strict_types=1);
namespace GroupShop\Manager;
if (!defined('ABSPATH')) { exit; }
/** Guest IDs, session cookies and Store API cart tokens are bound to one brand. */
class BrandSession extends \WC_Session_Handler {
    public static function belongs(string $id): bool {
        if (ctype_digit($id)) { return Customers::local_customer((int)$id)||Brands::root((int)$id)||Identities::accessible((int)$id); }
        return str_starts_with($id,'t_b'.Brands::id().'_');
    }
    public function generate_customer_id() { $id=(string)parent::generate_customer_id(); if (ctype_digit($id)) { return $id; } $prefix='t_b'.Brands::id().'_'; return $prefix.substr($id,0,32-strlen($prefix)); }
    public function get_session_cookie() { $cookie=parent::get_session_cookie(); return $cookie&&self::belongs((string)$cookie[0])?$cookie:false; }
}
