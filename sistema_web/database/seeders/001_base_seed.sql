INSERT INTO roles (name, description, created_at, updated_at) VALUES
('user','Utilizador final',NOW(),NOW()),
('admin','Administrador',NOW(),NOW()),
('human_reviewer','Revisor humano',NOW(),NOW()),
('superadmin','Administrador global',NOW(),NOW());

INSERT INTO institutions (name, short_name, city, country, is_active, created_at, updated_at) VALUES
('Universidade Eduardo Mondlane','UEM','Maputo','Moçambique',1,NOW(),NOW()),
('Universidade Pedagógica de Maputo','UP Maputo','Maputo','Moçambique',1,NOW(),NOW()),
('Universidade Católica de Moçambique','UCM','Beira','Moçambique',1,NOW(),NOW());

INSERT INTO academic_levels (name, slug, multiplier, description, is_active) VALUES
('Técnico','tecnico',1.00,'Nível técnico',1),
('Licenciatura','licenciatura',1.20,'Graduação',1),
('Pós-graduação','pos-graduacao',1.35,'Especialização',1),
('Mestrado','mestrado',1.60,'Mestrado',1),
('Doutoramento','doutoramento',2.00,'Doutoramento',1);

INSERT INTO work_types (name, slug, description, is_active, base_price, default_complexity, allows_full_auto_generation, requires_human_review, is_premium_type, display_order, created_at, updated_at) VALUES
('Trabalho de pesquisa','trabalho-pesquisa','Pesquisa académica guiada',1,800,'medium',1,0,0,1,NOW(),NOW()),
('Projecto de pesquisa','projecto-pesquisa','Projecto académico',1,1500,'medium',1,0,0,2,NOW(),NOW()),
('Relatório de estágio','relatorio-estagio','Relatório académico',1,2000,'medium',1,0,0,3,NOW(),NOW()),
('Artigo científico','artigo-cientifico','Artigo académico',1,1200,'high',1,0,1,4,NOW(),NOW()),
('Monografia','monografia','Trabalho monográfico',1,4500,'high',0,1,1,99,NOW(),NOW());

INSERT INTO language_profiles (name, locale, academic_tone_level, is_active) VALUES
('academic_formal','pt_MZ','high',1),
('academic_humanized','pt_MZ','high',1),
('critical_reflective','pt_MZ','medium',1),
('technical_methodological','pt_MZ','high',1);

INSERT INTO citation_profiles (name, style_code, is_active) VALUES
('ABNT Padrão','ABNT',1),
('APA 7','APA',1);

INSERT INTO users (name, email, phone, password_hash, is_active, created_at, updated_at) VALUES
('VIP Estudante','vip@mozacad.test','841234567','$2y$10$examplehash',1,NOW(),NOW());

INSERT INTO user_discounts (user_id, name, discount_type, discount_value, usage_limit, used_count, starts_at, ends_at, is_active, created_by_admin_id, notes, created_at, updated_at)
VALUES (1,'VIP 10%','percent',10,100,NOW(),NOW(),DATE_ADD(NOW(), INTERVAL 30 DAY),1,1,'Benefício promocional VIP',NOW(),NOW());
