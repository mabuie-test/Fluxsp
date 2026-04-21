INSERT INTO pricing_extras (extra_code, name, price, is_active, created_at, updated_at) VALUES
('needs_institution_cover','Capa institucional',200,1,NOW(),NOW()),
('needs_bilingual_abstract','Abstract bilíngue',300,1,NOW(),NOW()),
('needs_methodology_review','Revisão metodológica',500,1,NOW(),NOW()),
('needs_humanized_revision','Revisão humanizada',400,1,NOW(),NOW()),
('needs_slides','Apresentação em slides',800,1,NOW(),NOW()),
('needs_defense_summary','Resumo de defesa',450,1,NOW(),NOW());

INSERT INTO work_type_structures (work_type_id, section_code, section_title, section_order, is_required, min_words, max_words, notes) VALUES
(1,'intro','Introdução',1,1,300,800,'Contextualização do tema'),
(1,'metodologia','Metodologia',2,1,400,1200,'Métodos utilizados'),
(1,'analise','Análise e Discussão',3,1,600,2500,'Corpo principal'),
(1,'conclusao','Conclusão',4,1,250,700,'Fecho do trabalho'),
(5,'intro','Introdução',1,1,600,1500,'Obrigatória para monografia'),
(5,'referencial','Referencial Teórico',2,1,1200,4000,'Obrigatória para monografia'),
(5,'metodologia','Metodologia',3,1,1000,3000,'Obrigatória para monografia'),
(5,'resultados','Resultados e Discussão',4,1,1500,5000,'Obrigatória para monografia'),
(5,'conclusao','Conclusões e Recomendações',5,1,500,1200,'Obrigatória para monografia');
