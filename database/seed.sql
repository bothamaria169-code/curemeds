-- ============================================================================
--  CureMeds — starter catalogue and content
--
--  Every price, product and article here is a starting point for the admin to
--  edit in Admin → Products. Photos are from Unsplash and Pexels (free to use
--  commercially) and live in assets/img/stock/.
-- ============================================================================
SET NAMES utf8mb4;

-- ── Accounts ─────────────────────────────────────────────────────────────
-- Staff:   admin@curemeds.co.za — the password is in ADMIN-LOGIN.txt on the computer that
--          built the site (never in GitHub). Change it after your first sign-in.
-- Patient: phone 082 000 0001 / PIN 1234           (demo — delete before launch)
INSERT INTO `users` (`id`,`name`,`email`,`password`,`role`,`phone`,`pin_hash`,`conditions`,`reminders_enabled`) VALUES
(1,'CureMeds Admin','admin@curemeds.co.za','$2y$12$FHLGi2opOW9pxXPQE7j57eAqEJQQkV96uwOE4uIBlAzP9XjtT.hIq','admin','27835761713',NULL,NULL,0),
(2,'Demo Patient',NULL,NULL,'customer','27820000001','$2y$12$lR8efExa90tvU1hJKVSyXeiQPFAI9elcS1M0l0dVCBpVs5Ri51Rjm','Immune support',1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- ── Brands ───────────────────────────────────────────────────────────────
INSERT INTO `brands` (`id`,`name`,`slug`,`tagline`,`blurb`,`accent`,`sort_order`) VALUES
(1,'ShieldWell','shieldwell','Health protection matters.','Daily immune-support supplements for people managing long-term health conditions, taken alongside the treatment their clinic prescribes.','#1557D0',1),
(2,'CureMeds Essentials','curemeds-essentials','The everyday basics, done properly.','Vitamins, minerals and gut-health staples at honest prices, chosen by our team for the conditions our patients ask about most.','#1BB3C8',2),
(3,'CureMeds Care','curemeds-care','Tools that make a routine stick.','Pill organisers, blood-pressure monitors and the small things that help you take the right dose at the right time.','#0A1B3D',3)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- ── Categories ───────────────────────────────────────────────────────────
INSERT INTO `categories` (`id`,`name`,`slug`,`icon`,`blurb`,`image`,`sort_order`) VALUES
(1,'Immune Support','immune-support','fa-shield-heart','Daily support for a stronger immune system.','assets/img/stock/immune-vitc-zinc.jpg',1),
(2,'Vitamins & Minerals','vitamins-minerals','fa-capsules','Multivitamins, B-complex, iron and the everyday basics.','assets/img/stock/multivitamin.jpg',2),
(3,'Heart & Circulation','heart-circulation','fa-heart-pulse','Omega-3 and tools to keep an eye on your blood pressure.','assets/img/stock/omega3.jpg',3),
(4,'Digestive Health','digestive-health','fa-seedling','Probiotics and greens for a settled gut.','assets/img/stock/probiotic.jpg',4),
(5,'Sleep & Stress','sleep-stress','fa-moon','Gentle herbal support for rest and calm.','assets/img/stock/sleep-drops.jpg',5),
(6,'Joints & Mobility','joints-mobility','fa-person-walking','Glucosamine and collagen for moving comfortably.','assets/img/stock/joint-support.jpg',6),
(7,'Skin & Beauty','skin-beauty','fa-spa','Collagen and skin care that works from both sides.','assets/img/stock/body-lotion.jpg',7),
(8,'Health Devices & Care','devices-care','fa-stethoscope','Monitors, organisers and everyday care items.','assets/img/stock/bp-monitor.jpg',8)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- ── Products ─────────────────────────────────────────────────────────────
INSERT INTO `products`
(`id`,`category_id`,`brand_id`,`name`,`slug`,`short_desc`,`description`,`benefits`,`usage_notes`,`type`,`retail_price`,`sale_price`,`pack_size`,`sku`,`stock_status`,`stock_qty`,`featured_image`,`is_featured`,`is_sale`,`is_bestseller`,`is_new`,`tags`) VALUES

(1,1,1,'ShieldWell HIV Immune Support','shieldwell-hiv-immune-support',
 'A daily immune-support tablet for people living with HIV, taken alongside — never instead of — the ARV treatment your clinic prescribes.',
 '<p>ShieldWell combines the vitamins and minerals people living with HIV most often run low on into one daily tablet, so a supplement routine is as simple as the treatment routine it sits next to.</p><p><b>ShieldWell is not a treatment for HIV and does not replace antiretroviral therapy.</b> Keep taking your ARVs exactly as prescribed, and tell your clinic you are taking a supplement.</p>',
 "Supports normal immune function\nHelps fill common nutrient gaps\nOne tablet a day, easy to pair with your ARV routine\nGentle on the stomach when taken with food",
 'Take one tablet daily with food, at the same time as a meal. Do not exceed the stated dose. Keep taking all prescribed medication.',
 'simple',450.00,NULL,'60 tablets','SW-HIV-60','in_stock',120,'assets/img/stock/shieldwell.jpg',1,0,1,1,'hiv,immune,arv support,shieldwell,daily'),

(2,1,2,'Immune Defence Vitamin C + Zinc','immune-defence-vitamin-c-zinc',
 'Vitamin C and zinc together in one capsule for everyday immune support, especially through the winter months.',
 '<p>Two of the best-studied nutrients for immune health in a single slow-release capsule.</p>',
 "1000mg vitamin C with 15mg zinc\nSlow-release for all-day support\nSupports normal immune function",
 'One capsule daily after a meal.',
 'simple',185.00,159.00,'60 capsules','CME-VCZ-60','in_stock',200,'assets/img/stock/immune-vitc-zinc.jpg',1,1,1,0,'vitamin c,zinc,immune,winter,flu season'),

(3,2,2,'Daily Multivitamin Complete','daily-multivitamin-complete',
 'A once-a-day multivitamin and mineral tablet covering the basics for adults.',
 '<p>Twenty-two vitamins and minerals at sensible daily levels — a safety net for days when your diet does not cover everything.</p>',
 "22 vitamins and minerals\nOne small tablet a day\nSuitable for adults over 18",
 'One tablet daily with breakfast.',
 'simple',165.00,NULL,'30 tablets','CME-MULTI-30','in_stock',240,'assets/img/stock/multivitamin.jpg',1,0,1,0,'multivitamin,daily,energy,minerals'),

(4,3,2,'Omega-3 Fish Oil 1000mg','omega-3-fish-oil-1000mg',
 'Purified fish oil softgels rich in EPA and DHA, the omega-3 fats for heart and brain health.',
 '<p>Molecularly distilled to remove impurities, with a lemon coating so there is no fishy aftertaste.</p>',
 "Rich in EPA and DHA\nSupports heart health\nNo fishy aftertaste",
 'Two softgels daily with a meal.',
 'simple',220.00,NULL,'90 softgels','CME-OM3-90','in_stock',150,'assets/img/stock/omega3.jpg',1,0,0,0,'omega 3,fish oil,heart,cholesterol,brain'),

(5,2,2,'B-Complex Energy','b-complex-energy',
 'All eight B vitamins to support energy release from food and reduce tiredness.',
 '<p>B vitamins help the body turn food into energy. This complex includes B12 and folate at useful daily levels.</p>',
 "All eight B vitamins\nSupports energy metabolism\nHelps reduce tiredness and fatigue",
 'One tablet daily with breakfast. May colour urine bright yellow — this is normal.',
 'simple',140.00,NULL,'60 tablets','CME-BCX-60','in_stock',180,'assets/img/stock/b-complex.jpg',0,0,0,0,'vitamin b,b12,energy,fatigue'),

(6,2,2,'Iron + Folic Acid','iron-folic-acid',
 'Gentle iron with folic acid to support healthy red blood cells.',
 '<p>A gentle form of iron that is easier on the stomach, paired with folic acid.</p>',
 "Supports normal red blood cell formation\nGentle iron, less stomach upset\nWith folic acid",
 'One tablet daily, ideally with orange juice. Keep out of reach of children.',
 'simple',120.00,NULL,'60 tablets','CME-IRON-60','in_stock',140,'assets/img/stock/iron-folic.jpg',0,0,0,0,'iron,folic acid,anaemia,blood,pregnancy'),

(7,4,2,'Probiotic Gut Balance','probiotic-gut-balance',
 'Ten billion live cultures per capsule for a balanced gut, especially during and after a course of antibiotics.',
 '<p>Ten strains, ten billion CFU, in acid-resistant capsules that reach the gut alive.</p>',
 "10 billion CFU, 10 strains\nAcid-resistant capsules\nUseful during and after antibiotics",
 'One capsule daily before breakfast. If you are on antibiotics, take it at least two hours apart from them.',
 'simple',260.00,NULL,'30 capsules','CME-PRO-30','in_stock',110,'assets/img/stock/probiotic.jpg',1,0,0,1,'probiotic,gut,digestion,antibiotics,bloating'),

(8,4,2,'Greens Detox Powder','greens-detox-powder',
 'A daily scoop of spinach, spirulina, wheatgrass and fibre to stir into water or a smoothie.',
 '<p>Twelve greens and a gentle fibre blend for days when vegetables are thin on the plate.</p>',
 "12 greens in one scoop\nAdded fibre for regularity\nMixes into water or smoothies",
 'One level scoop (8g) in 250ml water or a smoothie, once daily.',
 'simple',299.00,NULL,'250g','CME-GRN-250','in_stock',90,'assets/img/stock/greens-detox.jpg',0,0,0,1,'greens,detox,fibre,spirulina'),

(9,5,2,'Calm & Sleep Herbal Drops','calm-sleep-herbal-drops',
 'Valerian, chamomile and passionflower drops for winding down at night.',
 '<p>A traditional herbal blend in an easy dropper. Non-habit-forming.</p>',
 "Valerian, chamomile and passionflower\nNon-habit-forming\nEasy-dose dropper",
 '20 drops in a little water 30 minutes before bed. Do not drive after use. Not for use during pregnancy.',
 'simple',175.00,NULL,'30ml','CME-SLP-30','in_stock',100,'assets/img/stock/sleep-drops.jpg',0,0,0,0,'sleep,stress,valerian,anxiety,calm'),

(10,6,2,'Joint Flex Glucosamine','joint-flex-glucosamine',
 'Glucosamine, chondroitin and MSM for joints that feel stiff in the morning.',
 '<p>The classic joint trio at full daily doses. Give it six to eight weeks to judge it.</p>',
 "Glucosamine 1500mg + chondroitin + MSM\nSupports joint comfort and mobility\nFull daily dose in two capsules",
 'Two capsules daily with food. Speak to your doctor first if you take blood thinners or have a shellfish allergy.',
 'simple',285.00,249.00,'60 capsules','CME-JNT-60','in_stock',80,'assets/img/stock/joint-support.jpg',1,1,0,0,'joints,glucosamine,arthritis,mobility,knees'),

(11,7,2,'Marine Collagen Powder','marine-collagen-powder',
 'Unflavoured hydrolysed marine collagen that dissolves into coffee, tea or water.',
 '<p>Type I collagen peptides from fish, with vitamin C added to support the body\'s own collagen production.</p>',
 "10g collagen peptides per serving\nUnflavoured, dissolves hot or cold\nWith added vitamin C",
 'One scoop (10g) daily in any drink.',
 'simple',349.00,NULL,'300g','CME-COL-300','in_stock',70,'assets/img/stock/collagen.jpg',0,0,1,0,'collagen,skin,hair,nails,joints'),

(12,7,2,'Barrier Repair Body Lotion','barrier-repair-body-lotion',
 'A fragrance-free lotion with ceramides and urea for dry, sensitive or medication-dried skin.',
 '<p>Some chronic medication leaves skin dry and itchy. This fragrance-free formula restores the skin barrier without stinging.</p>',
 "Ceramides and 5% urea\nFragrance-free for sensitive skin\nAbsorbs quickly, no greasy feel",
 'Apply to clean skin twice daily, or as often as needed.',
 'simple',159.00,NULL,'200ml','CME-LOT-200','in_stock',130,'assets/img/stock/body-lotion.jpg',0,0,0,0,'lotion,dry skin,eczema,fragrance free'),

(13,8,2,'Headache & Fever Relief 500mg','headache-fever-relief-500mg',
 'Paracetamol 500mg tablets for headaches, body aches and fever.',
 '<p>Fast relief for everyday aches and pains. Always read the leaflet.</p>',
 "Paracetamol 500mg\nFor headaches, body pain and fever\nGentle on the stomach",
 'Adults: 1–2 tablets every 4–6 hours as needed. Do not take more than 8 tablets in 24 hours. Do not take with other medicines containing paracetamol.',
 'simple',45.00,NULL,'24 tablets','CME-PCM-24','in_stock',300,'assets/img/stock/pain-relief.jpg',0,0,0,0,'paracetamol,headache,fever,pain'),

(14,8,3,'Weekly Pill Organiser','weekly-pill-organiser',
 'Fourteen compartments — morning and evening for every day of the week — so you always know whether you took it.',
 '<p>Snap-shut lids with large day labels. Pairs perfectly with the CureMeds patient portal reminders.</p>',
 "Morning and evening for 7 days\nLarge, easy-read labels\nSnap-shut, travel-safe lids",
 'Fill once a week. Wash with warm soapy water.',
 'simple',89.00,NULL,'1 organiser','CMC-ORG-14','in_stock',160,'assets/img/stock/pill-organiser.jpg',0,0,0,0,'pill box,organiser,reminder,adherence'),

(15,8,3,'Digital Blood Pressure Monitor','digital-blood-pressure-monitor',
 'An automatic upper-arm monitor that stores 120 readings and flags an irregular heartbeat.',
 '<p>Measure at home, then log your readings in the CureMeds health tracker so your progress is in one place.</p>',
 "Automatic upper-arm cuff (22–42cm)\nStores 120 readings for two users\nIrregular heartbeat indicator",
 'Sit quietly for five minutes before measuring. Measure at the same time each day.',
 'simple',649.00,599.00,'1 monitor','CMC-BPM-01','in_stock',40,'assets/img/stock/bp-monitor.jpg',1,1,0,0,'blood pressure,monitor,hypertension,heart'),

(16,8,3,'3-Ply Face Masks (50)','3-ply-face-masks-50',
 'Disposable three-ply face masks with a nose wire and soft ear loops.',
 '<p>For clinic visits, crowded transport and caring for someone who is unwell.</p>',
 "Three-ply filtration\nAdjustable nose wire\nSoft ear loops",
 'Single use. Replace when damp.',
 'simple',120.00,NULL,'50 masks','CMC-MSK-50','in_stock',250,'assets/img/stock/masks.jpg',0,0,0,0,'masks,face mask,clinic,protection')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `product_gallery` (`product_id`,`image`,`caption`,`sort_order`) VALUES
(1,'assets/img/stock/pill-box.jpg','Pairs with a weekly pill box',1),
(1,'assets/img/stock/patient-care.jpg','Take it alongside your prescribed treatment',2),
(15,'assets/img/stock/bp-check.jpg','Log every reading in your health tracker',1);

-- ── Testimonials ─────────────────────────────────────────────────────────
INSERT INTO `testimonials` (`name`,`role`,`location`,`quote`,`rating`,`is_reseller`,`sort_order`) VALUES
('Thandi M.','Patient','Soweto, Gauteng','The email at eight every morning is the reason I have not missed a dose in two months. I tap the button and it is logged.',5,0,1),
('Sipho K.','Patient','Durban, KZN','I asked on WhatsApp which vitamins were safe with my treatment. They answered the same afternoon and my order came two days later.',5,0,2),
('Lerato D.','Caregiver','Centurion','I look after my mother\'s medication. Having her pill times and side effects written down in one place makes the clinic visits much easier.',5,0,3),
('Johan P.','Patient','Bloemfontein','Bought the blood pressure monitor and now log my readings every morning. My doctor liked seeing the chart.',4,0,4);

-- ── FAQs ─────────────────────────────────────────────────────────────────
INSERT INTO `faqs` (`question`,`answer`,`topic`,`sort_order`) VALUES
('How do I order?','Press "Enquire on WhatsApp" on any product, or add a few products to your list and send them all at once. Your message arrives at +27 83 576 1713 with the products and prices filled in. We confirm stock, the total and delivery, and send payment details.','Ordering',1),
('How do I pay?','Once we confirm your order on WhatsApp we send our banking details for an EFT. Send the proof of payment back on the same WhatsApp chat and we dispatch the same or next working day.','Ordering',2),
('How long does delivery take?','Courier delivery takes 2–4 working days to main centres and up to 5 working days to outlying areas. We send the tracking number on WhatsApp.','Delivery',1),
('What is the patient portal?','After you buy, we set up your medication schedule. You sign in with your cellphone number and a 4-digit PIN to tick off doses, log how you feel and report any side effects. If you give us your email address, we email you a reminder at every dose time.','Patient portal',1),
('I forgot my PIN. What now?','Send us a WhatsApp from the number you registered with and we will reset it. For your privacy we never send PINs by email or SMS.','Patient portal',2),
('What should I do if I get a side effect?','Report it in the portal under "Side effects" so our team can see it, and stop the product if it is bothering you. If it is severe — difficulty breathing, swelling of the face or a rash that spreads — go to your nearest clinic or call 10177 immediately.','Patient portal',3),
('Can I take supplements with my chronic medication?','Many people do, but some combinations interact. Always tell your doctor or clinic what you take. Supplements never replace prescribed medication — including ARVs, blood pressure and diabetes treatment.','Health',1),
('Are the supplements registered?','Supplements sold here are complementary medicines. Unless stated otherwise they have not been evaluated by SAHPRA for quality, safety or intended use, and are not intended to diagnose, treat, cure or prevent any disease.','Health',2),
('Is my health information private?','Yes. Only you and authorised CureMeds staff can see your medication, readings and side-effect reports. We handle your information under POPIA and never sell or share it.','Privacy',1),
('Can I return something?','Unopened products in their original packaging can be returned within 7 days of delivery. Opened health products cannot be returned for hygiene reasons. Anything damaged in transit is replaced free.','Delivery',2);

-- ── Learn hub ────────────────────────────────────────────────────────────
INSERT INTO `blogs` (`title`,`slug`,`image`,`excerpt`,`content`,`category`,`author_id`,`read_mins`,`is_featured`) VALUES
('Taking supplements alongside chronic medication','supplements-with-chronic-medication','assets/img/stock/patient-care.jpg',
 'Supplements can help fill gaps, but some combinations matter. What to ask your clinic before you start anything new.',
 '<p>Most people managing a long-term condition can take everyday supplements safely. A few combinations, though, change how well medication works.</p><h3>Tell your clinic everything</h3><p>Bring the bottle to your next visit, or a photo of the label. Your nurse or doctor can check it against your treatment in a minute.</p><h3>Timing matters</h3><p>Iron, calcium and zinc can reduce how much of some medicines your body absorbs. Taking them a few hours apart usually solves it — your clinic will tell you if it applies to you.</p><h3>Never swap one for the other</h3><p>No supplement replaces prescribed treatment, including ARVs, blood pressure tablets or insulin. Keep taking your medication exactly as prescribed.</p>',
 'Health',1,5,1),

('Five ways to stop missing doses','five-ways-to-stop-missing-doses','assets/img/stock/pill-box.jpg',
 'Missed doses are rarely about willpower. Small systems — a pill box, a fixed time, a reminder — do most of the work.',
 '<p>Nearly everyone on daily medication misses a dose sometimes. The fix is a routine that does not depend on memory.</p><h3>1. Tie it to something you already do</h3><p>Breakfast, brushing your teeth, the evening news. Same time, same place.</p><h3>2. Use a weekly pill box</h3><p>Fill it on Sunday. An empty compartment tells you that you took it.</p><h3>3. Turn on reminders</h3><p>Add your email in the CureMeds portal and we will email you at each dose time, with a one-tap button to log it.</p><h3>4. Keep a spare dose in your bag</h3><p>For the days you do not make it home.</p><h3>5. Track it</h3><p>Seeing your adherence climb week by week is surprisingly motivating.</p>',
 'Routine',1,4,1),

('When a side effect needs attention','when-a-side-effect-needs-attention','assets/img/stock/nurse-bp.jpg',
 'Most side effects are mild and fade. Some need a clinic today. How to tell the difference — and how to report them.',
 '<p>New products can cause mild effects in the first week or two: an upset stomach, a headache, changes in sleep. These usually settle.</p><h3>Report it anyway</h3><p>Log it in the portal under Side effects. Our team reviews every report and replies there.</p><h3>Go to a clinic straight away if you have</h3><ul><li>difficulty breathing or swelling of the lips, face or throat</li><li>a rash that spreads or blisters</li><li>yellowing of the skin or eyes</li><li>severe stomach pain or vomiting that will not stop</li></ul><p>In an emergency call 10177 or go to your nearest emergency unit.</p>',
 'Health',1,4,0);

-- ── Settings ─────────────────────────────────────────────────────────────
INSERT INTO `settings` (`key_name`,`value`) VALUES
('site_name','CureMeds'),
('site_tagline','Health products and supplements, with care that follows up.'),
('currency_symbol','R'),
('free_shipping_threshold','1000'),
('shipping_flat_rate','100'),
('contact_phone','+27 83 576 1713'),
('contact_whatsapp','27835761713'),
('contact_email','admin@curemeds.co.za'),
('contact_address','South Africa — nationwide courier delivery'),
('facebook_url',''),
('instagram_url',''),
('tiktok_url',''),
('maintenance_mode','0'),
('newsletter_gate_mode','off'),
('announcement','Order on WhatsApp: +27 83 576 1713 · Courier delivery nationwide in 2–4 working days')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);

-- ── Demo patient: one medication and a few days of history ───────────────
INSERT INTO `patient_medications` (`id`,`user_id`,`product_id`,`name`,`dosage`,`instructions`,`dose_times`,`start_date`,`units_left`,`units_per_dose`,`added_by`) VALUES
(1,2,1,'ShieldWell HIV Immune Support','1 tablet','With breakfast, alongside ARVs','08:00',CURDATE() - INTERVAL 10 DAY,50,1,1),
(2,2,2,'Immune Defence Vitamin C + Zinc','1 capsule','After supper','19:00',CURDATE() - INTERVAL 10 DAY,52,1,1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

UPDATE `products` SET `sold_count` = FLOOR(RAND()*120)+15, `view_count` = FLOOR(RAND()*700)+90;

-- Demo patient history, so the tracker and admin charts have something to show. Remove before launch.
INSERT IGNORE INTO `dose_logs` (`medication_id`,`user_id`,`scheduled_for`,`status`,`source`) VALUES
(1,2,CONCAT(CURDATE() - INTERVAL 10 DAY, ' 08:00:00'),'taken','portal'),
(2,2,CONCAT(CURDATE() - INTERVAL 10 DAY, ' 19:00:00'),'taken','portal'),
(1,2,CONCAT(CURDATE() - INTERVAL 9 DAY, ' 08:00:00'),'taken','email'),
(2,2,CONCAT(CURDATE() - INTERVAL 9 DAY, ' 19:00:00'),'taken','portal'),
(1,2,CONCAT(CURDATE() - INTERVAL 8 DAY, ' 08:00:00'),'taken','portal'),
(2,2,CONCAT(CURDATE() - INTERVAL 8 DAY, ' 19:00:00'),'taken','portal'),
(1,2,CONCAT(CURDATE() - INTERVAL 7 DAY, ' 08:00:00'),'taken','portal'),
(2,2,CONCAT(CURDATE() - INTERVAL 7 DAY, ' 19:00:00'),'skipped','portal'),
(1,2,CONCAT(CURDATE() - INTERVAL 6 DAY, ' 08:00:00'),'taken','email'),
(2,2,CONCAT(CURDATE() - INTERVAL 6 DAY, ' 19:00:00'),'taken','portal'),
(1,2,CONCAT(CURDATE() - INTERVAL 5 DAY, ' 08:00:00'),'taken','portal'),
(2,2,CONCAT(CURDATE() - INTERVAL 5 DAY, ' 19:00:00'),'taken','portal'),
(2,2,CONCAT(CURDATE() - INTERVAL 4 DAY, ' 19:00:00'),'taken','portal'),
(1,2,CONCAT(CURDATE() - INTERVAL 3 DAY, ' 08:00:00'),'taken','email'),
(2,2,CONCAT(CURDATE() - INTERVAL 3 DAY, ' 19:00:00'),'taken','portal'),
(1,2,CONCAT(CURDATE() - INTERVAL 2 DAY, ' 08:00:00'),'taken','portal'),
(2,2,CONCAT(CURDATE() - INTERVAL 2 DAY, ' 19:00:00'),'skipped','portal'),
(1,2,CONCAT(CURDATE() - INTERVAL 1 DAY, ' 08:00:00'),'taken','portal'),
(2,2,CONCAT(CURDATE() - INTERVAL 1 DAY, ' 19:00:00'),'taken','portal');
INSERT IGNORE INTO `health_readings` (`user_id`,`recorded_on`,`systolic`,`diastolic`,`weight_kg`,`glucose`,`mood`,`energy`) VALUES
(2,CURDATE() - INTERVAL 9 DAY,132,86,78.4,6.1,3,3),
(2,CURDATE() - INTERVAL 7 DAY,128,84,78.1,5.8,4,3),
(2,CURDATE() - INTERVAL 5 DAY,126,82,77.9,NULL,4,4),
(2,CURDATE() - INTERVAL 3 DAY,124,81,77.6,5.6,4,4),
(2,CURDATE() - INTERVAL 1 DAY,121,80,77.4,5.5,5,4);
INSERT INTO `side_effects` (`user_id`,`medication_id`,`symptom`,`severity`,`started_on`,`details`,`status`,`staff_reply`,`reviewed_by`,`reviewed_at`,`created_at`) VALUES
(2,1,'Mild nausea in the morning','mild',CURDATE() - INTERVAL 8 DAY,'Feels queasy for about an hour after the tablet.','reviewed','Thanks for telling us. Try taking it with a full breakfast rather than just tea — that usually settles it. Let us know if it carries on past a week.',1,NOW() - INTERVAL 7 DAY,NOW() - INTERVAL 8 DAY);

-- GlucoBalance — added from the packaging artwork in the project folder.
-- Confirm the price and pack size in Admin → Products before launch.
INSERT INTO `brands` (`id`,`name`,`slug`,`tagline`,`blurb`,`accent`,`sort_order`) VALUES
(4,'GlucoBalance','glucobalance','Healthy blood sugar balance.','Daily support for people looking after their blood sugar, designed to sit alongside the diet, exercise and treatment their clinic recommends.','#1E9E6A',2)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `categories` (`id`,`name`,`slug`,`icon`,`blurb`,`image`,`sort_order`) VALUES
(9,'Blood Sugar Support','blood-sugar-support','fa-droplet','Daily support for healthy glucose levels.','assets/img/stock/glucobalance.jpg',2)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `products`
(`id`,`category_id`,`brand_id`,`name`,`slug`,`short_desc`,`description`,`benefits`,`usage_notes`,`type`,`retail_price`,`pack_size`,`sku`,`stock_status`,`stock_qty`,`featured_image`,`is_featured`,`is_bestseller`,`is_new`,`tags`) VALUES
(17,9,4,'GlucoBalance Diabetes Support','glucobalance-diabetes-support',
 'A daily tablet that supports healthy blood sugar balance, taken alongside — never instead of — your diabetes treatment.',
 '<p>GlucoBalance brings together nutrients traditionally used to support healthy glucose metabolism in one daily tablet, for people who are already looking after their blood sugar with their clinic.</p><p><b>GlucoBalance is not a treatment for diabetes and does not replace insulin, metformin or any medication you have been prescribed.</b> Keep taking your treatment exactly as prescribed, keep checking your sugar, and tell your clinic you are taking a supplement — some supplements can change how your blood sugar responds to medication.</p>',
 "Supports healthy blood sugar balance\nPromotes normal metabolic health\nSupports overall wellness\nOne tablet a day with a meal",
 'Take one tablet daily with your main meal. Check your blood sugar as your clinic advises and log it in your CureMeds tracker. Speak to your clinic before starting if you use insulin or sulphonylureas.',
 'simple',395.00,'60 tablets','GB-DIA-60','in_stock',100,'assets/img/stock/glucobalance.jpg',1,1,1,'diabetes,blood sugar,glucose,glucobalance,metabolic')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `product_gallery` (`product_id`,`image`,`caption`,`sort_order`) VALUES
(17,'assets/img/stock/healthy-food.jpg','Works best alongside a balanced diet',1),
(17,'assets/img/stock/fitness.jpg','and regular movement',2);
