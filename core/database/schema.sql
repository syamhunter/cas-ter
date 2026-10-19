-- =====================================================================
-- POS SaaS multi-tenant: skema database (InnoDB, utf8mb4)
-- Kolom JSON: tipe JSON (MySQL 5.7+) atau alias LONGTEXT + JSON_VALID (MariaDB)
-- Jangan memakai titik koma di dalam komentar (installer menjalankan file ini apa adanya)
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(100) NOT NULL,
  price_monthly DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  max_branches SMALLINT UNSIGNED NULL,
  max_users SMALLINT UNSIGNED NULL,
  max_transactions_month INT UNSIGNED NULL,
  allowed_modules JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  custom_domain VARCHAR(190) NULL,
  owner_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) NULL,
  status ENUM('trial','active','suspended','expired') NOT NULL DEFAULT 'trial',
  subscription_ends_at DATETIME NULL,
  timezone VARCHAR(40) NOT NULL DEFAULT 'Asia/Jakarta',
  currency CHAR(3) NOT NULL DEFAULT 'IDR',
  settings JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenants_slug (slug),
  UNIQUE KEY uq_tenants_domain (custom_domain),
  KEY idx_tenants_status (status, subscription_ends_at),
  CONSTRAINT fk_tenants_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_modules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  module_code VARCHAR(30) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  settings JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenant_module (tenant_id, module_code),
  CONSTRAINT fk_tm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  address TEXT NULL,
  phone VARCHAR(30) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  settings JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branch_code (tenant_id, code),
  CONSTRAINT fk_branch_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NULL,
  branch_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','owner','manager','cashier') NOT NULL DEFAULT 'cashier',
  permissions JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_tenant_role (tenant_id, role),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE owner_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_user_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  status ENUM('trial','active','suspended','expired') NOT NULL DEFAULT 'trial',
  subscription_ends_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_owner_accounts_user (owner_user_id),
  CONSTRAINT fk_owner_accounts_user FOREIGN KEY (owner_user_id) REFERENCES users (id),
  CONSTRAINT fk_owner_accounts_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE owner_account_tenants (
  owner_account_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (owner_account_id, tenant_id),
  UNIQUE KEY uq_owner_account_tenant (tenant_id),
  CONSTRAINT fk_owner_account_tenants_account FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_owner_account_tenants_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_ip (ip, created_at),
  KEY idx_la_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_user_id INT UNSIGNED NOT NULL,
  action_code VARCHAR(60) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_activity_time (created_at),
  KEY idx_admin_activity_entity (entity_type, entity_id),
  CONSTRAINT fk_admin_activity_user FOREIGN KEY (admin_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_no VARCHAR(40) NOT NULL,
  billing_key VARCHAR(50) NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  owner_account_id INT UNSIGNED NULL,
  plan_id INT UNSIGNED NULL,
  plan_name VARCHAR(100) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  issued_at DATE NOT NULL,
  due_at DATE NOT NULL,
  grace_until DATE NOT NULL,
  status ENUM('unpaid','paid','overdue','void') NOT NULL DEFAULT 'unpaid',
  paid_at DATE NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscription_invoice_no (invoice_no),
  KEY idx_subscription_invoice_due (status, due_at),
  KEY idx_subscription_invoice_tenant (tenant_id, created_at),
  CONSTRAINT fk_subscription_invoice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_subscription_invoice_account FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id),
  CONSTRAINT fk_subscription_invoice_plan FOREIGN KEY (plan_id) REFERENCES plans (id),
  CONSTRAINT fk_subscription_invoice_admin FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  owner_account_id INT UNSIGNED NULL,
  amount DECIMAL(15,2) NOT NULL,
  paid_at DATE NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  payment_method ENUM('bank_transfer','qris','cash','other') NOT NULL DEFAULT 'bank_transfer',
  reference VARCHAR(120) NOT NULL,
  notes VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_subscription_payment_tenant (tenant_id, paid_at),
  KEY idx_subscription_payment_account (owner_account_id, paid_at),
  UNIQUE KEY uq_subscription_payment_reference (tenant_id, reference),
  CONSTRAINT fk_subscription_payment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_subscription_payment_account FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id),
  CONSTRAINT fk_subscription_payment_admin FOREIGN KEY (recorded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id BIGINT UNSIGNED NOT NULL,
  billing_key VARCHAR(50) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  paid_at DATE NOT NULL,
  payment_method ENUM('bank_transfer','qris','cash','other') NOT NULL DEFAULT 'bank_transfer',
  reference VARCHAR(120) NOT NULL,
  notes VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_payment_invoice (invoice_id),
  UNIQUE KEY uq_invoice_payment_reference (billing_key, reference),
  KEY idx_invoice_payment_date (paid_at),
  CONSTRAINT fk_invoice_payment_invoice FOREIGN KEY (invoice_id) REFERENCES subscription_invoices (id),
  CONSTRAINT fk_invoice_payment_admin FOREIGN KEY (recorded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_payment_proofs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id BIGINT UNSIGNED NOT NULL,
  billing_key VARCHAR(50) NOT NULL,
  owner_account_id INT UNSIGNED NOT NULL,
  submitted_by INT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  paid_at DATE NOT NULL,
  payment_method ENUM('bank_transfer','qris','cash','other') NOT NULL DEFAULT 'bank_transfer',
  reference VARCHAR(120) NOT NULL,
  file_path VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewer_note VARCHAR(500) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_proof_reference (billing_key, reference),
  KEY idx_invoice_proof_review (status, created_at),
  KEY idx_invoice_proof_owner (owner_account_id, created_at),
  CONSTRAINT fk_invoice_proof_invoice FOREIGN KEY (invoice_id) REFERENCES subscription_invoices (id),
  CONSTRAINT fk_invoice_proof_owner FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id),
  CONSTRAINT fk_invoice_proof_submitter FOREIGN KEY (submitted_by) REFERENCES users (id),
  CONSTRAINT fk_invoice_proof_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_no VARCHAR(40) NOT NULL,
  owner_account_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NULL,
  category ENUM('technical','billing','account','other') NOT NULL DEFAULT 'other',
  subject VARCHAR(150) NOT NULL,
  status ENUM('open','in_progress','waiting_owner','resolved') NOT NULL DEFAULT 'open',
  last_message_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  owner_read_at DATETIME(6) NULL,
  admin_read_at DATETIME(6) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_support_ticket_no (ticket_no),
  KEY idx_support_ticket_owner (owner_account_id, updated_at),
  KEY idx_support_ticket_admin (status, updated_at),
  KEY idx_support_ticket_invoice (invoice_id),
  CONSTRAINT fk_support_ticket_owner FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id),
  CONSTRAINT fk_support_ticket_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_support_ticket_invoice FOREIGN KEY (invoice_id) REFERENCES subscription_invoices (id),
  CONSTRAINT fk_support_ticket_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL,
  sender_user_id INT UNSIGNED NOT NULL,
  sender_role ENUM('owner','super_admin') NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_support_message_ticket (ticket_id, id),
  CONSTRAINT fk_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
  CONSTRAINT fk_support_message_user FOREIGN KEY (sender_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  attributes JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cust_phone (tenant_id, phone),
  KEY idx_cust_name (tenant_id, name),
  CONSTRAINT fk_cust_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cat_tenant (tenant_id, parent_id, sort_order),
  CONSTRAINT fk_cat_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Produk, layanan, dan menu dalam satu tabel. Atribut khusus industri ada di kolom attributes (JSON)
CREATE TABLE products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NULL,
  name VARCHAR(190) NOT NULL,
  product_type ENUM('goods','menu','medicine','laundry','print','service') NOT NULL DEFAULT 'goods',
  pricing_model ENUM('unit','per_kg','per_m2','per_hour','fixed') NOT NULL DEFAULT 'unit',
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  base_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  cost_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  track_batch TINYINT(1) NOT NULL DEFAULT 0,
  min_stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  attributes JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_prod_name (tenant_id, name),
  KEY idx_prod_type (tenant_id, product_type, is_active),
  CONSTRAINT fk_prod_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL DEFAULT 'Default',
  sku VARCHAR(60) NULL,
  barcode VARCHAR(64) NULL,
  price DECIMAL(15,2) NULL,
  cost_price DECIMAL(15,2) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  attributes JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_var_barcode (tenant_id, barcode),
  KEY idx_var_sku (tenant_id, sku),
  KEY idx_var_product (product_id),
  CONSTRAINT fk_var_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_var_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  batch_number VARCHAR(60) NOT NULL,
  expired_at DATE NOT NULL,
  qty_initial DECIMAL(12,3) NOT NULL,
  qty_remaining DECIMAL(12,3) NOT NULL,
  cost_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  supplier_name VARCHAR(150) NULL,
  received_at DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_batch (tenant_id, branch_id, variant_id, batch_number),
  KEY idx_batch_fefo (tenant_id, branch_id, variant_id, expired_at, qty_remaining),
  KEY idx_batch_expired (tenant_id, expired_at),
  CONSTRAINT fk_batch_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_batch_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_batch_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_levels (
  branch_id INT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (branch_id, variant_id),
  KEY idx_stock_tenant (tenant_id, variant_id),
  CONSTRAINT fk_stock_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_stock_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_stock_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  movement_type ENUM('sale','purchase','adjustment','transfer_in','transfer_out','return','void') NOT NULL,
  qty_change DECIMAL(12,3) NOT NULL,
  qty_after DECIMAL(12,3) NOT NULL,
  reference_type VARCHAR(30) NULL,
  reference_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_variant (tenant_id, branch_id, variant_id, created_at),
  KEY idx_sm_ref (tenant_id, reference_type, reference_id),
  CONSTRAINT fk_sm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_sm_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_sm_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id),
  CONSTRAINT fk_sm_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id),
  CONSTRAINT fk_sm_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dining_tables (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  name VARCHAR(30) NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL DEFAULT 4,
  status ENUM('available','occupied','reserved','cleaning') NOT NULL DEFAULT 'available',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_table_name (branch_id, name),
  KEY idx_table_tenant (tenant_id, branch_id, status),
  CONSTRAINT fk_table_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_table_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Penomoran invoice atomik per cabang per hari
CREATE TABLE invoice_counters (
  branch_id INT UNSIGNED NOT NULL,
  counter_date DATE NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  last_number INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (branch_id, counter_date),
  CONSTRAINT fk_ic_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_ic_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  invoice_no VARCHAR(40) NOT NULL,
  idempotency_key CHAR(36) NOT NULL,
  order_type ENUM('retail','dine_in','takeaway','pharmacy','laundry','printing','service') NOT NULL DEFAULT 'retail',
  status ENUM('open','paid','void','refunded') NOT NULL DEFAULT 'open',
  payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  fulfillment_status VARCHAR(30) NULL,
  customer_id BIGINT UNSIGNED NULL,
  table_id INT UNSIGNED NULL,
  cashier_id INT UNSIGNED NOT NULL,
  cashier_shift_id BIGINT UNSIGNED NULL,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  service_charge DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  paid_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  change_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  notes VARCHAR(500) NULL,
  meta JSON NULL,
  due_at DATETIME NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trx_invoice (tenant_id, invoice_no),
  UNIQUE KEY uq_trx_idem (tenant_id, idempotency_key),
  KEY idx_trx_branch_date (tenant_id, branch_id, created_at),
  KEY idx_trx_quota (tenant_id, created_at),
  KEY idx_trx_fulfillment (tenant_id, order_type, fulfillment_status),
  KEY idx_trx_table (table_id),
  CONSTRAINT fk_trx_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_trx_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_trx_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT fk_trx_table FOREIGN KEY (table_id) REFERENCES dining_tables (id),
  CONSTRAINT fk_trx_cashier FOREIGN KEY (cashier_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  variant_id BIGINT UNSIGNED NULL,
  batch_id BIGINT UNSIGNED NULL,
  item_name VARCHAR(190) NOT NULL,
  item_type VARCHAR(20) NOT NULL DEFAULT 'goods',
  qty DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  cost_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  split_group TINYINT UNSIGNED NULL,
  kitchen_status VARCHAR(20) NULL,
  item_attributes JSON NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ti_trx (transaction_id),
  KEY idx_ti_kds (tenant_id, kitchen_status, created_at),
  KEY idx_ti_variant (tenant_id, variant_id),
  CONSTRAINT fk_ti_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_ti_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id),
  CONSTRAINT fk_ti_product FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT fk_ti_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id),
  CONSTRAINT fk_ti_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  method ENUM('cash','qris','transfer','debit','credit','other') NOT NULL DEFAULT 'cash',
  amount DECIMAL(15,2) NOT NULL,
  reference_no VARCHAR(80) NULL,
  split_group TINYINT UNSIGNED NULL,
  cashier_shift_id BIGINT UNSIGNED NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tp_trx (transaction_id),
  KEY idx_tp_report (tenant_id, method, paid_at),
  CONSTRAINT fk_tp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_tp_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_status_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  status_from VARCHAR(30) NULL,
  status_to VARCHAR(30) NOT NULL,
  changed_by INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tsl_trx (transaction_id, created_at),
  CONSTRAINT fk_tsl_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_tsl_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id),
  CONSTRAINT fk_tsl_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_returns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  cashier_shift_id BIGINT UNSIGNED NULL,
  refund_method ENUM('cash','qris','transfer','debit','credit','other') NOT NULL DEFAULT 'cash',
  refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  settled_refund_amount DECIMAL(15,2) NULL,
  note VARCHAR(255) NULL,
  returned_by INT UNSIGNED NOT NULL,
  returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_return_transaction (tenant_id, transaction_id, returned_at),
  CONSTRAINT fk_return_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_return_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_return_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id),
  CONSTRAINT fk_return_user FOREIGN KEY (returned_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_return_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id BIGINT UNSIGNED NOT NULL,
  transaction_item_id BIGINT UNSIGNED NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_return_item (return_id, transaction_item_id),
  KEY idx_return_item_transaction (transaction_item_id),
  CONSTRAINT fk_return_item_header FOREIGN KEY (return_id) REFERENCES transaction_returns (id),
  CONSTRAINT fk_return_item_line FOREIGN KEY (transaction_item_id) REFERENCES transaction_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE operating_expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  expense_date DATE NOT NULL,
  category VARCHAR(40) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  payment_method ENUM('cash','qris','transfer','debit','credit','other') NOT NULL DEFAULT 'cash',
  description VARCHAR(255) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expense_branch_date (tenant_id, branch_id, expense_date),
  CONSTRAINT fk_expense_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_expense_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_expense_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  action_code VARCHAR(60) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_tenant_time (tenant_id, created_at),
  KEY idx_audit_branch_time (tenant_id, branch_id, created_at),
  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_audit_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE uploads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  transaction_id BIGINT UNSIGNED NULL,
  purpose ENUM('design','prescription','product_image','other') NOT NULL DEFAULT 'other',
  original_name VARCHAR(190) NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  checksum_sha256 CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_up_trx (transaction_id),
  KEY idx_up_tenant (tenant_id, purpose, created_at),
  CONSTRAINT fk_up_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_up_user FOREIGN KEY (uploaded_by) REFERENCES users (id),
  CONSTRAINT fk_up_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prescriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NULL,
  prescription_no VARCHAR(40) NOT NULL,
  patient_name VARCHAR(150) NOT NULL,
  doctor_name VARCHAR(150) NOT NULL,
  doctor_license_no VARCHAR(60) NULL,
  issued_at DATE NOT NULL,
  details JSON NULL,
  upload_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rx_no (tenant_id, prescription_no),
  KEY idx_rx_trx (transaction_id),
  CONSTRAINT fk_rx_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_rx_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_rx_trx FOREIGN KEY (transaction_id) REFERENCES transactions (id),
  CONSTRAINT fk_rx_upload FOREIGN KEY (upload_id) REFERENCES uploads (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cashier_shifts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  cashier_id INT UNSIGNED NOT NULL,
  opening_cash DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  expected_cash DECIMAL(15,2) NULL,
  counted_cash DECIMAL(15,2) NULL,
  cash_difference DECIMAL(15,2) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opening_note VARCHAR(255) NULL,
  closing_note VARCHAR(255) NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  closed_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_shift_open (tenant_id, branch_id, status),
  KEY idx_shift_report (tenant_id, branch_id, opened_at),
  CONSTRAINT fk_shift_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_shift_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_shift_cashier FOREIGN KEY (cashier_id) REFERENCES users (id),
  CONSTRAINT fk_shift_closed_by FOREIGN KEY (closed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cashier_shift_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  shift_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  movement_type ENUM('cash_in','cash_out') NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shift_movement (tenant_id, branch_id, shift_id, created_at),
  CONSTRAINT fk_shift_movement_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_shift_movement_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_shift_movement_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (id),
  CONSTRAINT fk_shift_movement_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  notes VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_supplier_tenant (tenant_id, is_active, name),
  CONSTRAINT fk_supplier_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  order_no VARCHAR(40) NOT NULL,
  status ENUM('ordered','received','cancelled') NOT NULL DEFAULT 'ordered',
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  received_by INT UNSIGNED NULL,
  due_at DATE NULL,
  ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_order_no (tenant_id, order_no),
  KEY idx_purchase_branch_status (tenant_id, branch_id, status, ordered_at),
  CONSTRAINT fk_purchase_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_purchase_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_purchase_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_purchase_received_by FOREIGN KEY (received_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  qty_ordered DECIMAL(12,3) NOT NULL,
  qty_received DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  unit_cost DECIMAL(15,2) NOT NULL,
  batch_number VARCHAR(60) NULL,
  expired_at DATE NULL,
  received_batch_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_purchase_item_order (purchase_order_id),
  CONSTRAINT fk_purchase_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_purchase_item_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id),
  CONSTRAINT fk_purchase_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_payables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  billed_amount DECIMAL(15,2) NOT NULL,
  paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  credited_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  due_at DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payable_order (tenant_id, purchase_order_id),
  KEY idx_payable_supplier_due (tenant_id, branch_id, supplier_id, due_at),
  CONSTRAINT fk_payable_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_payable_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_payable_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_payable_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  payable_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  payment_method ENUM('cash','qris','transfer','debit','credit','other') NOT NULL,
  reference_no VARCHAR(80) NULL,
  note VARCHAR(255) NULL,
  paid_by INT UNSIGNED NOT NULL,
  cashier_shift_id BIGINT UNSIGNED NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_supplier_payment_payable (tenant_id, payable_id, paid_at),
  CONSTRAINT fk_supplier_payment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_supplier_payment_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_supplier_payment_payable FOREIGN KEY (payable_id) REFERENCES supplier_payables (id),
  CONSTRAINT fk_supplier_payment_user FOREIGN KEY (paid_by) REFERENCES users (id),
  CONSTRAINT fk_supplier_payment_shift FOREIGN KEY (cashier_shift_id) REFERENCES cashier_shifts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_returns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  payable_id BIGINT UNSIGNED NOT NULL,
  total_amount DECIMAL(15,2) NOT NULL,
  note VARCHAR(255) NULL,
  returned_by INT UNSIGNED NOT NULL,
  returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_purchase_return_order (tenant_id, purchase_order_id, returned_at),
  CONSTRAINT fk_purchase_return_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_purchase_return_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_purchase_return_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_purchase_return_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id),
  CONSTRAINT fk_purchase_return_payable FOREIGN KEY (payable_id) REFERENCES supplier_payables (id),
  CONSTRAINT fk_purchase_return_user FOREIGN KEY (returned_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_return_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id BIGINT UNSIGNED NOT NULL,
  purchase_order_item_id BIGINT UNSIGNED NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  unit_cost DECIMAL(15,2) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchase_return_item (return_id, purchase_order_item_id),
  KEY idx_return_purchase_item (purchase_order_item_id),
  CONSTRAINT fk_return_purchase_header FOREIGN KEY (return_id) REFERENCES purchase_returns (id),
  CONSTRAINT fk_return_purchase_line FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_counts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  status ENUM('open','posted') NOT NULL DEFAULT 'open',
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  posted_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_stock_count_branch (tenant_id, branch_id, status, created_at),
  CONSTRAINT fk_stock_count_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_stock_count_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
  CONSTRAINT fk_stock_count_user FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_stock_count_posted_by FOREIGN KEY (posted_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_count_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  stock_count_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  expected_qty DECIMAL(12,3) NOT NULL,
  counted_qty DECIMAL(12,3) NULL,
  variance_qty DECIMAL(12,3) NULL,
  PRIMARY KEY (id),
  KEY idx_stock_count_item (stock_count_id, variant_id),
  CONSTRAINT fk_stock_count_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_stock_count_item_count FOREIGN KEY (stock_count_id) REFERENCES stock_counts (id),
  CONSTRAINT fk_stock_count_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id),
  CONSTRAINT fk_stock_count_item_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  from_branch_id INT UNSIGNED NOT NULL,
  to_branch_id INT UNSIGNED NOT NULL,
  status ENUM('in_transit','received','cancelled') NOT NULL DEFAULT 'in_transit',
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  received_by INT UNSIGNED NULL,
  shipped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_transfer_from (tenant_id, from_branch_id, status, shipped_at),
  KEY idx_transfer_to (tenant_id, to_branch_id, status, shipped_at),
  CONSTRAINT fk_transfer_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_transfer_from FOREIGN KEY (from_branch_id) REFERENCES branches (id),
  CONSTRAINT fk_transfer_to FOREIGN KEY (to_branch_id) REFERENCES branches (id),
  CONSTRAINT fk_transfer_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_transfer_received_by FOREIGN KEY (received_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transfer_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id INT UNSIGNED NOT NULL,
  transfer_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  source_batch_id BIGINT UNSIGNED NULL,
  batch_number VARCHAR(60) NULL,
  expired_at DATE NULL,
  qty DECIMAL(12,3) NOT NULL,
  unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_transfer_item (transfer_id, variant_id),
  CONSTRAINT fk_transfer_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_transfer_item_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id),
  CONSTRAINT fk_transfer_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id),
  CONSTRAINT fk_transfer_item_batch FOREIGN KEY (source_batch_id) REFERENCES product_batches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
