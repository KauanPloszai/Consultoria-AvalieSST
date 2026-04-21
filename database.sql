CREATE DATABASE IF NOT EXISTS avaliiesst
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE avaliiesst;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  role ENUM('admin', 'company') NOT NULL DEFAULT 'admin',
  company_id INT UNSIGNED NULL,
  password_hash CHAR(64) NOT NULL,
  password_salt CHAR(32) NOT NULL,
  password_iterations INT UNSIGNED NOT NULL DEFAULT 120000,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_users_role (role),
  KEY idx_users_company (company_id)
);

CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  cnpj VARCHAR(32) NOT NULL UNIQUE,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  employees_count INT UNSIGNED NOT NULL DEFAULT 1,
  active_form_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_companies_active_form (active_form_id)
);

CREATE TABLE IF NOT EXISTS company_sectors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  sector_name VARCHAR(120) NOT NULL,
  employees_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_company_sectors_company
    FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE,
  UNIQUE KEY uniq_company_sector (company_id, sector_name)
);

CREATE TABLE IF NOT EXISTS company_functions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  sector_id INT UNSIGNED NOT NULL,
  function_name VARCHAR(160) NOT NULL,
  employees_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_company_functions_company
    FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_company_functions_sector
    FOREIGN KEY (sector_id) REFERENCES company_sectors(id)
    ON DELETE CASCADE,
  UNIQUE KEY uniq_company_sector_function (sector_id, function_name)
);

CREATE TABLE IF NOT EXISTS company_form_links (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  form_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_company_form_links_company
    FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_company_form_links_form
    FOREIGN KEY (form_id) REFERENCES forms(id)
    ON DELETE CASCADE,
  UNIQUE KEY uniq_company_form_link (company_id, form_id),
  KEY idx_company_form_links_company (company_id),
  KEY idx_company_form_links_form (form_id)
);

CREATE TABLE IF NOT EXISTS forms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS form_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  form_id INT UNSIGNED NOT NULL,
  question_text TEXT NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_form_questions_form
    FOREIGN KEY (form_id) REFERENCES forms(id)
    ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS employee_access_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  company_id INT UNSIGNED NOT NULL,
  form_id INT UNSIGNED NOT NULL,
  scope_type ENUM('global', 'sector', 'function') NOT NULL DEFAULT 'global',
  sector_id INT UNSIGNED NULL,
  function_id INT UNSIGNED NULL,
  scope_label VARCHAR(120) NOT NULL DEFAULT 'Todos os setores (Codigo Global)',
  expires_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  access_link_token VARCHAR(64) NULL,
  CONSTRAINT fk_employee_access_codes_company
    FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_employee_access_codes_form
    FOREIGN KEY (form_id) REFERENCES forms(id)
    ON DELETE CASCADE,
  KEY idx_access_codes_company_active (company_id, is_active),
  KEY idx_access_codes_scope_sector (sector_id),
  KEY idx_access_codes_scope_function (function_id),
  KEY idx_access_link_token (access_link_token)
);

CREATE TABLE IF NOT EXISTS access_code_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  access_code_id INT UNSIGNED NOT NULL,
  session_public_id VARCHAR(20) NOT NULL UNIQUE,
  ip_masked VARCHAR(64) NOT NULL DEFAULT 'IP oculto',
  status ENUM('pending', 'done') NOT NULL DEFAULT 'pending',
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  CONSTRAINT fk_access_code_sessions_code
    FOREIGN KEY (access_code_id) REFERENCES employee_access_codes(id)
    ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS access_code_answers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  form_question_id INT UNSIGNED NOT NULL,
  answer_value TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_access_code_answers_session
    FOREIGN KEY (session_id) REFERENCES access_code_sessions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_access_code_answers_question
    FOREIGN KEY (form_question_id) REFERENCES form_questions(id)
    ON DELETE CASCADE,
  UNIQUE KEY uniq_access_code_answer (session_id, form_question_id)
);

INSERT INTO users (id, name, email, role, company_id, password_hash, password_salt, password_iterations, is_active)
VALUES
  (1, 'Admin Principal', 'admin@avaliiesst.com', 'admin', NULL, 'b59c1b8f822bdae783073c9bba059bdc09b9726576512cff055475a14b7468fa', '5d24f0d93344320e52b7e564d83a9d27', 120000, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  role = VALUES(role),
  company_id = VALUES(company_id),
  password_hash = VALUES(password_hash),
  password_salt = VALUES(password_salt),
  password_iterations = VALUES(password_iterations),
  is_active = VALUES(is_active);

INSERT INTO companies (id, name, cnpj, status, employees_count, active_form_id)
VALUES
  (1, 'Tech Brasil', '12.546.546/0001-80', 'active', 24, 1),
  (2, 'Tech Lan', '98.765.432/0002-18', 'inactive', 18, 2),
  (3, 'Tech Sul', '45.678.901/0003-22', 'active', 120, 3)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  status = VALUES(status),
  employees_count = VALUES(employees_count),
  active_form_id = VALUES(active_form_id);

DELETE FROM company_sectors WHERE company_id IN (1, 2, 3);

INSERT INTO company_sectors (company_id, sector_name, employees_count)
VALUES
  (1, 'Tecnologia', 12),
  (1, 'Software', 12),
  (2, 'Tecnologia', 10),
  (2, 'Infraestrutura', 8),
  (3, 'Producao', 68),
  (3, 'Manufatura', 52);

INSERT INTO forms (id, public_code, name, status)
VALUES
  (1, '2026-01', 'Avaliacao de Estresse Ocupacional', 'active'),
  (2, '2026-02', 'Clima Organizacional e Burnout', 'inactive'),
  (3, '2026-03', 'Pesquisa de Engajamento Base', 'active')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  status = VALUES(status);

INSERT INTO company_form_links (company_id, form_id)
VALUES
  (1, 1),
  (2, 2),
  (3, 3)
ON DUPLICATE KEY UPDATE
  updated_at = CURRENT_TIMESTAMP;

DELETE FROM form_questions WHERE form_id IN (1, 2, 3);

INSERT INTO form_questions (form_id, question_text, position)
VALUES
  (1, 'Como voce percebe a sua carga de trabalho na rotina?', 1),
  (1, 'Com que frequencia sente pressao por prazos curtos?', 2),
  (1, 'Voce consegue fazer pausas adequadas durante o expediente?', 3),
  (2, 'Como voce avalia o clima da equipe nos ultimos meses?', 1),
  (2, 'Voce sente apoio da lideranca para lidar com dificuldades?', 2),
  (2, 'Ha abertura para dialogo e feedback no setor?', 3),
  (3, 'Com que frequencia voce se sente engajado nas atividades do setor?', 1),
  (3, 'Voce entende com clareza suas prioridades de trabalho?', 2),
  (3, 'Seu trabalho faz sentido para voce?', 3);

DELETE FROM company_functions WHERE company_id IN (1, 2, 3);

INSERT INTO company_functions (company_id, sector_id, function_name, employees_count)
SELECT 1, id, 'Analista de Sistemas', 6
FROM company_sectors
WHERE company_id = 1 AND sector_name = 'Tecnologia'
UNION ALL
SELECT 1, id, 'Desenvolvedor', 6
FROM company_sectors
WHERE company_id = 1 AND sector_name = 'Software'
UNION ALL
SELECT 2, id, 'Analista de Infraestrutura', 8
FROM company_sectors
WHERE company_id = 2 AND sector_name = 'Infraestrutura'
UNION ALL
SELECT 3, id, 'Operador de Linha', 40
FROM company_sectors
WHERE company_id = 3 AND sector_name = 'Producao'
UNION ALL
SELECT 3, id, 'Supervisor de Manufatura', 12
FROM company_sectors
WHERE company_id = 3 AND sector_name = 'Manufatura';

INSERT INTO employee_access_codes (id, code, company_id, form_id, scope_type, sector_id, function_id, scope_label, expires_at, is_active, access_link_token)
VALUES
  (1, 'TECH-OP1-X789-2026', 1, 1, 'global', NULL, NULL, 'Todos os setores (Codigo Global)', '2026-12-31 23:59:59', 1, '3f421370651839ad7d988260'),
  (2, 'TECH-RH2-Y456-2026', 2, 2, 'global', NULL, NULL, 'Recursos Humanos', '2026-12-31 23:59:59', 1, '19fd6a1f03434afe5b7b2445')
ON DUPLICATE KEY UPDATE
  company_id = VALUES(company_id),
  form_id = VALUES(form_id),
  scope_type = VALUES(scope_type),
  sector_id = VALUES(sector_id),
  function_id = VALUES(function_id),
  scope_label = VALUES(scope_label),
  expires_at = VALUES(expires_at),
  is_active = VALUES(is_active),
  access_link_token = VALUES(access_link_token);

DELETE FROM access_code_sessions WHERE access_code_id IN (1, 2);

INSERT INTO access_code_sessions (access_code_id, session_public_id, ip_masked, status, started_at, completed_at)
VALUES
  (1, 'SES-1001', '192.168.**.***', 'pending', '2026-04-18 10:15:22', NULL),
  (1, 'SES-1002', '10.0.**.***', 'done', '2026-04-18 09:45:10', '2026-04-18 10:10:00'),
  (2, 'SES-1003', '172.16.**.***', 'done', '2026-04-18 09:30:05', '2026-04-18 09:55:00');

-- Credenciais iniciais:
-- E-mail: admin@avaliiesst.com
-- Senha: Admin@123
