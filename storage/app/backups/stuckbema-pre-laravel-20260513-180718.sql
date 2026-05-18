--
-- PostgreSQL database dump
--

\restrict cqlgEx4B13e7KTEHO87p8h0AMBvMTpW095z09Bpny8sLr8uUYa7bfBVUS0Sh48e

-- Dumped from database version 17.9 (Debian 17.9-0+deb13u1)
-- Dumped by pg_dump version 17.9 (Debian 17.9-0+deb13u1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: external_work_orders; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.external_work_orders (
    work_order_number text NOT NULL,
    source text,
    recipient_name text,
    recipient_phone text,
    delivery_address text,
    original_payload jsonb NOT NULL,
    status text DEFAULT 'open'::text NOT NULL,
    received_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.external_work_orders OWNER TO postgres;

--
-- Name: order_items; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.order_items (
    id bigint NOT NULL,
    order_id text NOT NULL,
    artikel text NOT NULL,
    antal text NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    work_order_number text,
    delivered_antal text,
    delivered_at timestamp with time zone
);


ALTER TABLE public.order_items OWNER TO postgres;

--
-- Name: order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.order_items_id_seq OWNER TO postgres;

--
-- Name: order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.order_items_id_seq OWNED BY public.order_items.id;


--
-- Name: orders; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.orders (
    id text NOT NULL,
    adress text NOT NULL,
    tele text,
    mottagare text NOT NULL,
    status text DEFAULT 'pending'::text NOT NULL,
    assigned_driver_id text,
    created_by text,
    updated_by text,
    delivered_by text,
    delivered_note text,
    delivered_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    driver_id text,
    tracking_enabled boolean DEFAULT false NOT NULL,
    tracking_token text,
    tracking_url text,
    started_at timestamp with time zone,
    current_location jsonb,
    external_work_order_number text,
    original_work_order_snapshot jsonb,
    tracking_session_id text,
    last_stopped_at timestamp with time zone,
    notes text,
    recipient_email text,
    desired_delivery_date date,
    desired_delivery_time time without time zone,
    internal_comment text,
    priority text,
    sms_sent_at timestamp with time zone,
    sms_status text,
    sms_error text
);


ALTER TABLE public.orders OWNER TO postgres;

--
-- Name: people; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.people (
    id text NOT NULL,
    user_id text,
    name text NOT NULL,
    phone text,
    email text,
    image_path text,
    photo_url text,
    role text NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.people OWNER TO postgres;

--
-- Name: push_subscriptions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.push_subscriptions (
    id text NOT NULL,
    user_id text NOT NULL,
    endpoint text NOT NULL,
    p256dh text NOT NULL,
    auth text NOT NULL,
    platform text NOT NULL,
    user_agent text,
    permission text,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    token text,
    provider text NOT NULL,
    device_name text,
    app_version text,
    enabled boolean DEFAULT true NOT NULL,
    last_seen_at timestamp with time zone,
    last_success_at timestamp with time zone,
    last_failure_at timestamp with time zone,
    failure_count integer DEFAULT 0 NOT NULL,
    revoked_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.push_subscriptions OWNER TO postgres;

--
-- Name: push_subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.push_subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.push_subscriptions_id_seq OWNER TO postgres;

--
-- Name: push_subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.push_subscriptions_id_seq OWNED BY public.push_subscriptions.id;


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.system_settings (
    id integer DEFAULT 1 NOT NULL,
    app_title text DEFAULT 'Stuckbema Leveransdokument'::text NOT NULL,
    company_name text DEFAULT 'Stuckbema'::text NOT NULL,
    delivery_title text DEFAULT 'Leveransdokument'::text NOT NULL,
    support_email text,
    support_phone text,
    order_number_prefix text DEFAULT 'LEV'::text NOT NULL,
    allow_push_notifications boolean DEFAULT true NOT NULL,
    admin_message text,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_by text
);


ALTER TABLE public.system_settings OWNER TO postgres;

--
-- Name: tracking_links; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.tracking_links (
    token text NOT NULL,
    order_id text NOT NULL,
    active boolean DEFAULT true NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.tracking_links OWNER TO postgres;

--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    id text NOT NULL,
    email text NOT NULL,
    email_key text NOT NULL,
    first_name text NOT NULL,
    last_name text NOT NULL,
    role text NOT NULL,
    password_hash text NOT NULL,
    is_first_login boolean DEFAULT true NOT NULL,
    active boolean DEFAULT true NOT NULL,
    phone text,
    image_path text,
    photo_url text,
    person_id text,
    reset_token text,
    reset_token_expires timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    visibility text DEFAULT 'offline'::text NOT NULL,
    last_seen_at timestamp with time zone,
    CONSTRAINT users_role_check CHECK ((role = ANY (ARRAY['owner'::text, 'manager'::text, 'admin'::text, 'supervisor'::text, 'driver'::text, 'worker'::text, 'viewer'::text])))
);


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: work_order_delivery_events; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.work_order_delivery_events (
    id text NOT NULL,
    work_order_number text NOT NULL,
    order_id text NOT NULL,
    item_index integer NOT NULL,
    artikel text NOT NULL,
    delivered_antal text NOT NULL,
    delivered_at timestamp with time zone DEFAULT now() NOT NULL,
    delivered_by text,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.work_order_delivery_events OWNER TO postgres;

--
-- Name: order_items id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.order_items ALTER COLUMN id SET DEFAULT nextval('public.order_items_id_seq'::regclass);


--
-- Name: push_subscriptions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.push_subscriptions ALTER COLUMN id SET DEFAULT nextval('public.push_subscriptions_id_seq'::regclass);


--
-- Data for Name: external_work_orders; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.external_work_orders (work_order_number, source, recipient_name, recipient_phone, delivery_address, original_payload, status, received_at, updated_at) FROM stdin;
\.


--
-- Data for Name: order_items; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.order_items (id, order_id, artikel, antal, sort_order, work_order_number, delivered_antal, delivered_at) FROM stdin;
32	ord_69eda550-e55d-470f-99f5-719e053eedb3	Lättbetongblock	4	0	\N	\N	\N
37	ord_44fe43e9-6e0c-4d5b-baf4-201580e9d225	Tlp65c	6	0	\N	\N	\N
38	ord_63439f90-06d2-4875-92a7-5e47049abd93	TL11B	9	0	\N	\N	\N
39	ord_63439f90-06d2-4875-92a7-5e47049abd93	R79	1	1	\N	\N	\N
40	ord_63439f90-06d2-4875-92a7-5e47049abd93	TL121	20 lpm	2	\N	\N	\N
46	ord_23a5e7e0-1c5c-403d-a5be-e49a3940fe0a	Konsoller	9	0	\N	\N	\N
\.


--
-- Data for Name: orders; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.orders (id, adress, tele, mottagare, status, assigned_driver_id, created_by, updated_by, delivered_by, delivered_note, delivered_at, created_at, updated_at, driver_id, tracking_enabled, tracking_token, tracking_url, started_at, current_location, external_work_order_number, original_work_order_snapshot, tracking_session_id, last_stopped_at, notes, recipient_email, desired_delivery_date, desired_delivery_time, internal_comment, priority, sms_sent_at, sms_status, sms_error) FROM stdin;
ord_44fe43e9-6e0c-4d5b-baf4-201580e9d225	Tyrgatan 4	0707319739	Grzegorz	delivered	Billy	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	Ok	2026-05-11 07:05:55.58506+02	2026-05-08 13:05:28.667616+02	2026-05-11 07:05:55.58506+02	\N	f	\N	\N	\N	\N	\N	\N	\N	\N		winch.Grzegorz@gmail.com	2026-05-10	07:00:00			\N	\N	\N
ord_69eda550-e55d-470f-99f5-719e053eedb3	Tyrgatan 4	0729676110	Emil	delivered	Billy	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	Ok	2026-05-11 07:06:04.686207+02	2026-05-08 13:10:30.562394+02	2026-05-11 07:06:04.686207+02	\N	f	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	2026-05-11	07:00:00	\N	\N	\N	\N	\N
ord_63439f90-06d2-4875-92a7-5e47049abd93	Engelbrektsgatan 13	0735517941	Andy	delivered	Billy	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1		2026-05-11 07:28:02.72833+02	2026-05-08 13:08:44.446539+02	2026-05-11 07:28:02.72833+02	\N	f	\N	\N	\N	\N	\N	\N	\N	\N			2026-05-10	08:00:00			\N	\N	\N
ord_23a5e7e0-1c5c-403d-a5be-e49a3940fe0a	Stureplan	0705874547	Bongska	delivered		usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1		2026-05-11 07:28:07.783002+02	2026-05-08 13:45:00.404989+02	2026-05-11 07:28:07.783002+02	\N	f	\N	\N	\N	\N	\N	\N	\N	\N			2026-05-10	09:00:00			\N	\N	\N
\.


--
-- Data for Name: people; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.people (id, user_id, name, phone, email, image_path, photo_url, role, active, created_at, updated_at) FROM stdin;
adam_mierzwinski	adam_mierzwinski	Adam Mierzwinski	0737222579	odam2011@gmail.com	personer/adam_mierzwinski.jpg	\N	worker	t	2026-05-03 11:33:22.086121+02	2026-05-03 11:33:22.086121+02
alan_bejar_mendoza	alan_bejar_mendoza	Alan Bejar Mendoza	0739814850	alan.bejer@stuckbema.se	personer/alan_bejar_mendoza.jpg	\N	worker	t	2026-05-03 11:33:22.270939+02	2026-05-03 11:33:22.270939+02
anders_ahlzen	anders_ahlzen	Anders Ahlzen	0706712677	anders_ahlzen@live.se	personer/anders_ahlzen.jpg	\N	worker	t	2026-05-03 11:33:22.456149+02	2026-05-03 11:33:22.456149+02
andre_hauser	andre_hauser	Andre Hauser	0735517941	andre.hauser@stuckbema.se	personer/andre_hauser.jpg	\N	worker	t	2026-05-03 11:33:22.640699+02	2026-05-03 11:33:22.640699+02
andrzej_motyka	andrzej_motyka	Andrzej Motyka	0737530055	motykaa391@gmail.com	personer/andrzej_motyka.jpg	\N	worker	t	2026-05-03 11:33:22.825516+02	2026-05-03 11:33:22.825516+02
ashari_omid	ashari_omid	Ashari Omid	0732307166	oasghari4@gmail.com	personer/ashari_omid.jpg	\N	worker	t	2026-05-03 11:33:23.009407+02	2026-05-03 11:33:23.009407+02
asta_roos	asta_roos	Asta Röös	0721872396	asta.roos.02@gmail.com	personer/asta_roos.jpg	\N	worker	t	2026-05-03 11:33:23.194375+02	2026-05-03 11:33:23.194375+02
billy_wallen	billy_wallen	Billy Wallén	0707669189	billy.wallen@stuckbema.se	personer/billy_wallen.jpg	\N	driver	t	2026-05-03 11:33:23.379022+02	2026-05-03 11:33:23.379022+02
cilla	cilla	Cilla	\N	cecilia.skure@4klovern.se	\N	\N	worker	t	2026-05-03 11:33:23.562972+02	2026-05-03 11:33:23.562972+02
conny_rosen	conny_rosen	Conny Rosén	073-423 54 90	conny.rosen@smpputsprodukter.se	personer/conny_rosen.jpg	\N	worker	t	2026-05-03 11:33:23.747352+02	2026-05-03 11:33:23.747352+02
daniel_hillman	daniel_hillman	Daniel Hillman	0709767359	daniel.hillman@stuckbema.se	personer/daniel_hillman.jpg	\N	manager	t	2026-05-03 11:33:23.932106+02	2026-05-03 11:33:23.932106+02
dominik_siek	dominik_siek	Dominik Siek	0736156390	dominik.siek@stuckbema.se	personer/dominik_siek.jpg	\N	worker	t	2026-05-03 11:33:24.116515+02	2026-05-03 11:33:24.116515+02
emil_esbjornsson	emil_esbjornsson	Emil Esbjörnsson	0729676110	emil.jose.esbjornsson@gmail.com	personer/emil_esbjornsson.jpg	\N	worker	t	2026-05-03 11:33:24.300608+02	2026-05-03 11:33:24.300608+02
francis_wooremaa_myy	francis_wooremaa_myy	Francis Wooremaa Myy	0702595876	francis.wooremaa@gmail.com	personer/francis_wooremaa_myy.jpg	\N	supervisor	t	2026-05-03 11:33:24.48449+02	2026-05-03 11:33:24.48449+02
grzegorz_glowania	grzegorz_glowania	Grzegorz Glowania	0729383830	talar88@wp.pl	personer/grzegorz_glowania.jpg	\N	worker	t	2026-05-03 11:33:24.669259+02	2026-05-03 11:33:24.669259+02
grzegorz_winch	grzegorz_winch	Grzegorz Winch	0707319739	winch.grzegorz@gmail.com	personer/grzegorz_winch.jpg	\N	worker	t	2026-05-03 11:33:24.854341+02	2026-05-03 11:33:24.854341+02
hani_awad	hani_awad	Hani Awad	0765356676	hani.awad@stuckbema.se	personer/hani_awad.jpg	\N	worker	t	2026-05-03 11:33:25.04046+02	2026-05-03 11:33:25.04046+02
hans_erik_bergstrom	hans_erik_bergstrom	Hans-Erik Bergström	0738916616	hans-93@hotmail.com	personer/hans_erik_bergstrom.jpg	\N	worker	t	2026-05-03 11:33:25.224906+02	2026-05-03 11:33:25.224906+02
ivo_llanos	ivo_llanos	Ivo Llanos	0721463229	ivo.llanos@stuckbema.se	personer/ivo_llanos.jpg	\N	manager	t	2026-05-03 11:33:25.408978+02	2026-05-03 11:33:25.408978+02
jacek_kurowski	jacek_kurowski	Jacek Kurowski	0704929249	jacekkurowski@hotmail.com	personer/jacek_kurowski.jpg	\N	worker	t	2026-05-03 11:33:25.592571+02	2026-05-03 11:33:25.592571+02
jacek_tomasz_jurus	jacek_tomasz_jurus	Jacek Tomasz Jurus	0739412393	promyk69@gmail.com	personer/jacek_tomasz_jurus.jpg	\N	worker	t	2026-05-03 11:33:25.777083+02	2026-05-03 11:33:25.777083+02
jacob_alm	jacob_alm	Jacob Alm	0709767354	jacob@stuckbema.se	personer/jacob_alm.jpg	\N	driver	t	2026-05-03 11:33:25.961907+02	2026-05-03 11:33:25.961907+02
jan_larsson	jan_larsson	Jan Larsson	0704-676544	jan.larsson@stuckbema.se	personer/jan_larsson.jpg	\N	owner	t	2026-05-03 11:33:26.146378+02	2026-05-03 11:33:26.146378+02
jerzy_jurus	jerzy_jurus	Jerzy Jurus	0760625071	sympatyk66@gmail.com	personer/jerzy_jurus.jpg	\N	worker	t	2026-05-03 11:33:26.329999+02	2026-05-03 11:33:26.329999+02
jerzy_siek	jerzy_siek	Jerzy Siek	0735695051	bambarylka1977@gmail.com	personer/jerzy_siek.jpg	\N	worker	t	2026-05-03 11:33:26.513999+02	2026-05-03 11:33:26.513999+02
jessica_helander	jessica_helander	Jessica Helander	0720585112	jessica.helander@4klovern.se	\N	\N	worker	t	2026-05-03 11:33:26.698041+02	2026-05-03 11:33:26.698041+02
jorge_toledo	jorge_toledo	Jorge Toledo	0736414225	jorge.toledo@stuckbema.se	personer/jorge_toledo.jpg	\N	worker	t	2026-05-03 11:33:26.883094+02	2026-05-03 11:33:26.883094+02
juliette_marchesini	juliette_marchesini	Juliette Marchesini	0762476997	juliette@gipsstuckaturer.se	personer/juliette_marchesini.jpg	\N	admin	t	2026-05-03 11:33:27.0677+02	2026-05-03 11:33:27.0677+02
kemal_bars	kemal_bars	Kemal Bars	0704413468	kemal.bars@hotmail.com	personer/kemal_bars.jpg	\N	worker	t	2026-05-03 11:33:27.252229+02	2026-05-03 11:33:27.252229+02
khalid_el_farik	khalid_el_farik	Khalid El Farik	0764274174	khalidprofisionnel@gmail.com	personer/khalid_el_farik.jpg	\N	worker	t	2026-05-03 11:33:27.436854+02	2026-05-03 11:33:27.436854+02
krystian_krille_motyka	krystian_krille_motyka	Krystian Krille Motyka	0739832027	krystianmotyka2006@gmail.com	personer/krystian_krille_motyka.jpg	\N	worker	t	2026-05-03 11:33:27.621679+02	2026-05-03 11:33:27.621679+02
layth_thameen_abbo_naqqar	layth_thameen_abbo_naqqar	Layth Thameen Abbo Naqqar	0735942820	layth_th_nakar@yahoo.com	personer/layth_thameen_abbo_naqqar.jpg	\N	worker	t	2026-05-03 11:33:27.808264+02	2026-05-03 11:33:27.808264+02
linda_wadman	linda_wadman	Linda Wadman	\N	linda.wadman@4klovern.se	\N	\N	worker	t	2026-05-03 11:33:27.993479+02	2026-05-03 11:33:27.993479+02
madde_extern_redov	madde_extern_redov	Madde Extern redov	\N	madde_extern_redov@stuckbema.local	\N	\N	viewer	t	2026-05-03 11:33:28.177321+02	2026-05-03 11:33:28.177321+02
micael_bergstrom	micael_bergstrom	Micael Bergström	0705105856	micael.bergstrom@stuckbema.se	personer/micael_bergstrom.jpg	\N	worker	t	2026-05-03 11:33:28.3636+02	2026-05-03 11:33:28.3636+02
michal_rejman	michal_rejman	Michal Rejman	0707186302	michal.rejman93@gmail.com	personer/michal_rejman.jpg	\N	worker	t	2026-05-03 11:33:28.54801+02	2026-05-03 11:33:28.54801+02
mikael_cajstedt	mikael_cajstedt	Mikael Cajstedt	0707828372	mikael.cajstedt@stuckbema.se	\N	\N	worker	t	2026-05-03 11:33:28.732174+02	2026-05-03 11:33:28.732174+02
mohamed_gasmi	mohamed_gasmi	Mohamed Gasmi	0738907750	mohamed_gasmi@hotmail.com	personer/mohamed_gasmi.jpg	\N	worker	t	2026-05-03 11:33:28.916529+02	2026-05-03 11:33:28.916529+02
monem_khaldi	monem_khaldi	Monem Khaldi	0709747025	monem.khaldi@stuckbema.se	personer/monem_khaldi.jpg	\N	worker	t	2026-05-03 11:33:29.100516+02	2026-05-03 11:33:29.100516+02
nasir_hassan	nasir_hassan	Nasir Hassan	0709192984	nasir.hassan@stuckbema.se	personer/nasir_hassan.jpg	\N	worker	t	2026-05-03 11:33:29.285043+02	2026-05-03 11:33:29.285043+02
nemat_josefi	nemat_josefi	Nemat Josefi	0709131200	nemojosefi@gmail.com	personer/nemat_josefi.jpg	\N	worker	t	2026-05-03 11:33:29.471278+02	2026-05-03 11:33:29.471278+02
nidal_kahoul	nidal_kahoul	Nidal Kahoul	073504287	kahoulnidal93@gmail.com	personer/nidal_kahoul.jpg	\N	worker	t	2026-05-03 11:33:29.655547+02	2026-05-03 11:33:29.655547+02
patryk_jaromin	patryk_jaromin	Patryk Jaromin	0707209595	jaromin.patryk@hotmail.com	personer/patryk_jaromin.jpg	\N	worker	t	2026-05-03 11:33:29.839822+02	2026-05-03 11:33:29.839822+02
tero_toikkanen	tero_toikkanen	Tero Toikkanen	0705874547	tero.toikkanen@stuckbema.se	personer/tero_toikkanen.jpg	\N	worker	t	2026-05-03 11:33:30.024512+02	2026-05-03 11:33:30.024512+02
tina_bincofiore	tina_bincofiore	Tina Bincofiore	\N	tina_bincofiore@stuckbema.local	\N	\N	worker	t	2026-05-03 11:33:30.208635+02	2026-05-03 11:33:30.208635+02
waclaw_mazuga	waclaw_mazuga	Waclaw Mazuga	0762732347	waclaw.mazuga@gmail.com	personer/waclaw_mazuga.jpg	\N	worker	t	2026-05-03 11:33:30.392971+02	2026-05-03 11:33:30.392971+02
\.


--
-- Data for Name: push_subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.push_subscriptions (id, user_id, endpoint, p256dh, auth, platform, user_agent, permission, updated_at, token, provider, device_name, app_version, enabled, last_seen_at, last_success_at, last_failure_at, failure_count, revoked_at, created_at) FROM stdin;
\.


--
-- Data for Name: system_settings; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.system_settings (id, app_title, company_name, delivery_title, support_email, support_phone, order_number_prefix, allow_push_notifications, admin_message, updated_at, updated_by) FROM stdin;
1	Stuckbema Leveransdokument	Stuckbema	Leveransdokument	\N	\N	LEV	t	\N	2026-05-02 12:30:00.509673+02	\N
\.


--
-- Data for Name: tracking_links; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.tracking_links (token, order_id, active, expires_at, created_at, updated_at) FROM stdin;
54c808627fcfffd63337de19dde7528c4dc9d2c27bdbe587a55ab8a4399c034f	ord_0905e7dc-992d-4ace-9635-355f7a0fc6a7	f	2026-05-06 17:43:08.715+02	2026-05-05 17:43:08.715449+02	2026-05-05 17:43:08.733514+02
e5e1efd21278ed547de1850d4f550c4846cc7324eccd89fe4f2fa02e829fa9af	ord_88a8b941-68c5-4e4c-98da-5ad937973fb3	f	2026-05-07 07:38:22.594+02	2026-05-06 07:38:22.594324+02	2026-05-06 08:47:54.80381+02
5660faac5e3ae9940893d370dd253f121fd5d96b94f7e7afb7fb7a3f3d60be7c	ord_bc5374c3-ef2a-46aa-86c5-00a13255df27	f	2026-05-06 09:32:27.577+02	2026-05-05 09:32:27.577477+02	2026-05-06 09:15:14.82776+02
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.users (id, email, email_key, first_name, last_name, role, password_hash, is_first_login, active, phone, image_path, photo_url, person_id, reset_token, reset_token_expires, created_at, updated_at, visibility, last_seen_at) FROM stdin;
usr_717367cb-7fcc-4465-8a27-430d2e285a57			Användare		worker	$2a$12$amftiW9MyqvidRyMyIn2tuu8IVLePNyQ5DR0sSpyCaE4iYz3CpqrO	t	t	\N	\N	\N	\N	\N	\N	2026-05-02 12:44:52.374976+02	2026-05-02 12:44:52.374976+02	offline	\N
adam_mierzwinski	odam2011@gmail.com	odam2011@gmail.com	Adam	Mierzwinski	worker	$2a$12$VtUNMrRMWTJikSBO1U87j.DN61yjzKaMSpYTjzAHgQudq.2X6Z5We	t	t	0737222579	personer/adam_mierzwinski.jpg	\N	adam_mierzwinski	\N	\N	2026-05-03 11:33:22.084042+02	2026-05-03 11:33:22.084042+02	offline	\N
alan_bejar_mendoza	alan.bejer@stuckbema.se	alan.bejer@stuckbema.se	Alan	Bejar Mendoza	worker	$2a$12$s15R2vcz3D67fk3PE3SMluBFGhtkS0vkqHxedOjhc5AH/wFFWDeiO	t	t	0739814850	personer/alan_bejar_mendoza.jpg	\N	alan_bejar_mendoza	\N	\N	2026-05-03 11:33:22.268629+02	2026-05-03 11:33:22.268629+02	offline	\N
anders_ahlzen	anders_ahlzen@live.se	anders_ahlzen@live.se	Anders	Ahlzen	worker	$2a$12$pwyvhRLgWcluck9plLqkIeRieKO7SbuOouO7kiXVZDeZYwxaZ682S	t	t	0706712677	personer/anders_ahlzen.jpg	\N	anders_ahlzen	\N	\N	2026-05-03 11:33:22.453843+02	2026-05-03 11:33:22.453843+02	offline	\N
andre_hauser	andre.hauser@stuckbema.se	andre.hauser@stuckbema.se	Andre	Hauser	worker	$2a$12$NuhefM/5hK1pFtT7lqV6xOzfFxt08P5Lbc2PcSTkzH.AATczuzVLW	t	t	0735517941	personer/andre_hauser.jpg	\N	andre_hauser	\N	\N	2026-05-03 11:33:22.638325+02	2026-05-03 11:33:22.638325+02	offline	\N
andrzej_motyka	motykaa391@gmail.com	motykaa391@gmail.com	Andrzej	Motyka	worker	$2a$12$aDk8DSUplJFNMJCK581ADOr1L3UsdP1Lnys2CDVY2SsYsSwpFycdm	t	t	0737530055	personer/andrzej_motyka.jpg	\N	andrzej_motyka	\N	\N	2026-05-03 11:33:22.823206+02	2026-05-03 11:33:22.823206+02	offline	\N
ashari_omid	oasghari4@gmail.com	oasghari4@gmail.com	Ashari	Omid	worker	$2a$12$OFoRCz/I6rP/FtNsyVNE7Og6rcYuQ4ZxWCOUz8Rj02qvGV7Z0jJH2	t	t	0732307166	personer/ashari_omid.jpg	\N	ashari_omid	\N	\N	2026-05-03 11:33:23.008492+02	2026-05-03 11:33:23.008492+02	offline	\N
asta_roos	asta.roos.02@gmail.com	asta.roos.02@gmail.com	Asta	Röös	worker	$2a$12$4f3CK56taeaeInK9Iv1Efu0mI7LP5zRl.sbD80yhL63KGeDW27Zzi	t	t	0721872396	personer/asta_roos.jpg	\N	asta_roos	\N	\N	2026-05-03 11:33:23.19165+02	2026-05-03 11:33:23.19165+02	offline	\N
billy_wallen	billy.wallen@stuckbema.se	billy.wallen@stuckbema.se	Billy	Wallén	driver	$2a$12$zCJD9tNM5E1wdCuO7h/6Cub7stKxQ9csM/QilzEJ8.grzCwIkWmbq	t	t	0707669189	personer/billy_wallen.jpg	\N	billy_wallen	\N	\N	2026-05-03 11:33:23.376776+02	2026-05-03 11:33:23.376776+02	offline	\N
cilla	cecilia.skure@4klovern.se	cecilia.skure@4klovern.se	Cilla	Stuckbema	worker	$2a$12$Oizr/82Y/SgmpwlCbAiy4uOqqD5QR9zrtasXEHBEkDWXBcxuLRGl.	t	t	\N	\N	\N	cilla	\N	\N	2026-05-03 11:33:23.560745+02	2026-05-03 11:33:23.560745+02	offline	\N
conny_rosen	conny.rosen@smpputsprodukter.se	conny.rosen@smpputsprodukter.se	Conny	Rosén	worker	$2a$12$j0LTnPzB6vOJDs9AbUw.2..iBWSWOandF1b0yDNTjv0x8EIE9r8WC	t	t	073-423 54 90	personer/conny_rosen.jpg	\N	conny_rosen	\N	\N	2026-05-03 11:33:23.74518+02	2026-05-03 11:33:23.74518+02	offline	\N
daniel_hillman	daniel.hillman@stuckbema.se	daniel.hillman@stuckbema.se	Daniel	Hillman	manager	$2a$12$Ggo29sky2/e1IjHpLInvjuRzehQ6977x1yhXDBJU8bvXkOw8Niawe	t	t	0709767359	personer/daniel_hillman.jpg	\N	daniel_hillman	\N	\N	2026-05-03 11:33:23.929726+02	2026-05-03 11:33:23.929726+02	offline	\N
dominik_siek	dominik.siek@stuckbema.se	dominik.siek@stuckbema.se	Dominik	Siek	worker	$2a$12$qtvabC79x2EakghWwdkKbufnwoQ1T./u7ybhY4h6BIH6A5T10agB.	t	t	0736156390	personer/dominik_siek.jpg	\N	dominik_siek	\N	\N	2026-05-03 11:33:24.114132+02	2026-05-03 11:33:24.114132+02	offline	\N
emil_esbjornsson	emil.jose.esbjornsson@gmail.com	emil.jose.esbjornsson@gmail.com	Emil	Esbjörnsson	worker	$2a$12$Q6RJonrUXXTnUNycPGLxXuLYedwf8h0YA3xVBSHut6CSf.4fE3ybS	t	t	0729676110	personer/emil_esbjornsson.jpg	\N	emil_esbjornsson	\N	\N	2026-05-03 11:33:24.298144+02	2026-05-03 11:33:24.298144+02	offline	\N
francis_wooremaa_myy	francis.wooremaa@gmail.com	francis.wooremaa@gmail.com	Francis	Wooremaa Myy	supervisor	$2a$12$O.3BYPihwWwIftDRmEgbYOeOSQ7PHoAP4q.H2dWEJ4oIjfJ1FzmJy	t	t	0702595876	personer/francis_wooremaa_myy.jpg	\N	francis_wooremaa_myy	\N	\N	2026-05-03 11:33:24.482427+02	2026-05-03 11:33:24.482427+02	offline	\N
grzegorz_glowania	talar88@wp.pl	talar88@wp.pl	Grzegorz	Glowania	worker	$2a$12$zg73C6j79jjpDB0FFcW04uAFVv2HzLGl04.3lr8yCWjCltKIEFy8u	t	t	0729383830	personer/grzegorz_glowania.jpg	\N	grzegorz_glowania	\N	\N	2026-05-03 11:33:24.667013+02	2026-05-03 11:33:24.667013+02	offline	\N
grzegorz_winch	winch.grzegorz@gmail.com	winch.grzegorz@gmail.com	Grzegorz	Winch	worker	$2a$12$1RokbsjzrUKg3mP2xwqfEu0u9KGF.pPxLqSN4TrrA3flBtfqJ5UWy	t	t	0707319739	personer/grzegorz_winch.jpg	\N	grzegorz_winch	\N	\N	2026-05-03 11:33:24.852112+02	2026-05-03 11:33:24.852112+02	offline	\N
hani_awad	hani.awad@stuckbema.se	hani.awad@stuckbema.se	Hani	Awad	worker	$2a$12$JPTtSuiKepTkzNLellMD.OGh9sRThfgjeRlb70uO3B9IMeNhfNGiC	t	t	0765356676	personer/hani_awad.jpg	\N	hani_awad	\N	\N	2026-05-03 11:33:25.036424+02	2026-05-03 11:33:25.036424+02	offline	\N
hans_erik_bergstrom	hans-93@hotmail.com	hans-93@hotmail.com	Hans-Erik	Bergström	worker	$2a$12$dMIC8bxDsCQyHYOaPT5QZeMqkb3.IXLOSZpaKozSiFJApebg/4w3i	t	t	0738916616	personer/hans_erik_bergstrom.jpg	\N	hans_erik_bergstrom	\N	\N	2026-05-03 11:33:25.2227+02	2026-05-03 11:33:25.2227+02	offline	\N
ivo_llanos	ivo.llanos@stuckbema.se	ivo.llanos@stuckbema.se	Ivo	Llanos	manager	$2a$12$dC.Ttar.X1/2jFDyVqx.CORj4vIi0VgiIfbqp4s67.x0/Y9TTJje2	t	t	0721463229	personer/ivo_llanos.jpg	\N	ivo_llanos	\N	\N	2026-05-03 11:33:25.406565+02	2026-05-03 11:33:25.406565+02	offline	\N
jacek_kurowski	jacekkurowski@hotmail.com	jacekkurowski@hotmail.com	Jacek	Kurowski	worker	$2a$12$dVXPZyq52Geslp.XZL8DZO6nVkM3jhKk9QlyPm4kYnGB98XDiq1fO	t	t	0704929249	personer/jacek_kurowski.jpg	\N	jacek_kurowski	\N	\N	2026-05-03 11:33:25.59041+02	2026-05-03 11:33:25.59041+02	offline	\N
jacek_tomasz_jurus	promyk69@gmail.com	promyk69@gmail.com	Jacek	Tomasz Jurus	worker	$2a$12$dngml.lX7dRGqXCbGeU7bugcpiJ/y1szunaE1EbwbcHtyrz9q0Wme	t	t	0739412393	personer/jacek_tomasz_jurus.jpg	\N	jacek_tomasz_jurus	\N	\N	2026-05-03 11:33:25.774924+02	2026-05-03 11:33:25.774924+02	offline	\N
jan_larsson	jan.larsson@stuckbema.se	jan.larsson@stuckbema.se	Jan	Larsson	owner	$2a$12$3WIgM.qtd1PFoH4OEWqt0.ZXAaoLkr7X/RP1x2f4041eUeg6Uu3JK	t	t	0704-676544	personer/jan_larsson.jpg	\N	jan_larsson	\N	\N	2026-05-03 11:33:26.144655+02	2026-05-03 11:33:26.144655+02	offline	\N
jerzy_jurus	sympatyk66@gmail.com	sympatyk66@gmail.com	Jerzy	Jurus	worker	$2a$12$uUkiCnz5eLiLJ33suqbzFecMMN8vKTmZ4c.Bg66m/TQ.I0hq7J43W	t	t	0760625071	personer/jerzy_jurus.jpg	\N	jerzy_jurus	\N	\N	2026-05-03 11:33:26.327766+02	2026-05-03 11:33:26.327766+02	offline	\N
jerzy_siek	bambarylka1977@gmail.com	bambarylka1977@gmail.com	Jerzy	Siek	worker	$2a$12$WXUGmmN3W/mfsV6ogRexqOP3L70v.XdWWXNUCIwqqKTTlVp4lfsGW	t	t	0735695051	personer/jerzy_siek.jpg	\N	jerzy_siek	\N	\N	2026-05-03 11:33:26.512061+02	2026-05-03 11:33:26.512061+02	offline	\N
jessica_helander	jessica.helander@4klovern.se	jessica.helander@4klovern.se	Jessica	Helander	worker	$2a$12$/MVzoej6kHvq.A7BcgQ95eEWc6Qy8Kpme6dYI/qZWjyY43bycIalS	t	t	0720585112	\N	\N	jessica_helander	\N	\N	2026-05-03 11:33:26.695657+02	2026-05-03 11:33:26.695657+02	offline	\N
jorge_toledo	jorge.toledo@stuckbema.se	jorge.toledo@stuckbema.se	Jorge	Toledo	worker	$2a$12$Q8cwhnn8/tGSST15.1uCCuoCK28./zWNdxY0NBLxb3AaT4DVEcts6	t	t	0736414225	personer/jorge_toledo.jpg	\N	jorge_toledo	\N	\N	2026-05-03 11:33:26.880733+02	2026-05-03 11:33:26.880733+02	offline	\N
juliette_marchesini	juliette@gipsstuckaturer.se	juliette@gipsstuckaturer.se	Juliette	Marchesini	admin	$2a$12$SJi53wBmu2D6sBU7cv.pGup9JwHxfFgRM/Ax/U4AmxhnEhf.tF42q	t	t	0762476997	personer/juliette_marchesini.jpg	\N	juliette_marchesini	\N	\N	2026-05-03 11:33:27.065376+02	2026-05-03 11:33:27.065376+02	offline	\N
kemal_bars	kemal.bars@hotmail.com	kemal.bars@hotmail.com	Kemal	Bars	worker	$2a$12$HJKOTkT9fX5ikw8F/VojO.HrJNpcd1B.6jviiYUltDrYEDgrBFDW6	t	t	0704413468	personer/kemal_bars.jpg	\N	kemal_bars	\N	\N	2026-05-03 11:33:27.250096+02	2026-05-03 11:33:27.250096+02	offline	\N
khalid_el_farik	khalidprofisionnel@gmail.com	khalidprofisionnel@gmail.com	Khalid	El Farik	worker	$2a$12$YY6Mp7z4S75i1GREsbmb9uRe51vDA0owauMB.M895nFuGjoq/GRHy	t	t	0764274174	personer/khalid_el_farik.jpg	\N	khalid_el_farik	\N	\N	2026-05-03 11:33:27.434436+02	2026-05-03 11:33:27.434436+02	offline	\N
usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	admin@stuckbema.se	admin@stuckbema.se	Admin	Stuckbema	owner	$2a$12$/b6LC2e2LKdLj3s.FL3upejS1ApX3hKsXKaS2V.mJc72BHHx7af7W	t	t	\N	\N	\N	\N	\N	\N	2026-05-02 12:45:22.941922+02	2026-05-12 17:43:27.118292+02	offline	2026-05-12 17:43:27.118292+02
krystian_krille_motyka	krystianmotyka2006@gmail.com	krystianmotyka2006@gmail.com	Krystian	Krille Motyka	worker	$2a$12$nTEYyiwjOLgegJkqAznITOFAIjSh.bSSu9gN8nKCHddOE2AP0hnMi	t	t	0739832027	personer/krystian_krille_motyka.jpg	\N	krystian_krille_motyka	\N	\N	2026-05-03 11:33:27.619348+02	2026-05-03 11:33:27.619348+02	offline	\N
layth_thameen_abbo_naqqar	layth_th_nakar@yahoo.com	layth_th_nakar@yahoo.com	Layth	Thameen Abbo Naqqar	worker	$2a$12$RTrAFZ4jY0RBD644.RBIBe8SxxkbEbsFNrQqwpkgKL.N.jXcK4yqG	t	t	0735942820	personer/layth_thameen_abbo_naqqar.jpg	\N	layth_thameen_abbo_naqqar	\N	\N	2026-05-03 11:33:27.806154+02	2026-05-03 11:33:27.806154+02	offline	\N
linda_wadman	linda.wadman@4klovern.se	linda.wadman@4klovern.se	Linda	Wadman	worker	$2a$12$w3fYV0ryvFdy420Te4dlP.gpMUS7Ufw1WrQMHRszX1t/9hegluRX.	t	t	\N	\N	\N	linda_wadman	\N	\N	2026-05-03 11:33:27.991151+02	2026-05-03 11:33:27.991151+02	offline	\N
madde_extern_redov	madde_extern_redov@stuckbema.local	madde_extern_redov@stuckbema.local	Madde	Extern redov	viewer	$2a$12$edBpgjKS4aBFtMoUb.UEiu9zfl9HEDXILEg9VCThYXS9.8PdkcApe	t	t	\N	\N	\N	madde_extern_redov	\N	\N	2026-05-03 11:33:28.175991+02	2026-05-03 11:33:28.175991+02	offline	\N
micael_bergstrom	micael.bergstrom@stuckbema.se	micael.bergstrom@stuckbema.se	Micael	Bergström	worker	$2a$12$OubwOrvd33mVGYKBQQXNvuKlX5Ey2opFN5QcHtJsy.dXAmqXRo3eS	t	t	0705105856	personer/micael_bergstrom.jpg	\N	micael_bergstrom	\N	\N	2026-05-03 11:33:28.359597+02	2026-05-03 11:33:28.359597+02	offline	\N
michal_rejman	michal.rejman93@gmail.com	michal.rejman93@gmail.com	Michal	Rejman	worker	$2a$12$bH.IW30W2Qr/oVVhVWW5K.TsupQLgiyJscQmPLRcq1IerE91wlahC	t	t	0707186302	personer/michal_rejman.jpg	\N	michal_rejman	\N	\N	2026-05-03 11:33:28.545794+02	2026-05-03 11:33:28.545794+02	offline	\N
mikael_cajstedt	mikael.cajstedt@stuckbema.se	mikael.cajstedt@stuckbema.se	Mikael	Cajstedt	worker	$2a$12$k2Si8VE.IY5zN0SbJdX8weRPlswwHN..Z.hZ4WxoTGKMHtYYkNIxi	t	t	0707828372	\N	\N	mikael_cajstedt	\N	\N	2026-05-03 11:33:28.729898+02	2026-05-03 11:33:28.729898+02	offline	\N
mohamed_gasmi	mohamed_gasmi@hotmail.com	mohamed_gasmi@hotmail.com	Mohamed	Gasmi	worker	$2a$12$r22F59W1ouaCd0/IAPF51.lII/2lHL70zRIDsxG.uueBkfFAhDpIu	t	t	0738907750	personer/mohamed_gasmi.jpg	\N	mohamed_gasmi	\N	\N	2026-05-03 11:33:28.914143+02	2026-05-03 11:33:28.914143+02	offline	\N
monem_khaldi	monem.khaldi@stuckbema.se	monem.khaldi@stuckbema.se	Monem	Khaldi	worker	$2a$12$SwrfYhCJttBuNNJ11StoWuaCvaaDtOGFhpWqwwEf7jMO2CDYQ2hqO	t	t	0709747025	personer/monem_khaldi.jpg	\N	monem_khaldi	\N	\N	2026-05-03 11:33:29.098355+02	2026-05-03 11:33:29.098355+02	offline	\N
nasir_hassan	nasir.hassan@stuckbema.se	nasir.hassan@stuckbema.se	Nasir	Hassan	worker	$2a$12$5Qe0oU5gAJAvHoG6D4N4TuXczy5GU/beydnWIhqx5KRUfFB7Ykx8i	t	t	0709192984	personer/nasir_hassan.jpg	\N	nasir_hassan	\N	\N	2026-05-03 11:33:29.282749+02	2026-05-03 11:33:29.282749+02	offline	\N
nemat_josefi	nemojosefi@gmail.com	nemojosefi@gmail.com	Nemat	Josefi	worker	$2a$12$XfhqN4sqL/TM6qbo4/IsgOdLXI/MYMVG8Tw/LKnmArOrPCr7JtFZi	t	t	0709131200	personer/nemat_josefi.jpg	\N	nemat_josefi	\N	\N	2026-05-03 11:33:29.467469+02	2026-05-03 11:33:29.467469+02	offline	\N
nidal_kahoul	kahoulnidal93@gmail.com	kahoulnidal93@gmail.com	Nidal	Kahoul	worker	$2a$12$.iSzjZfzRG66aBNX69w5QuhEvVRUPD1gprpLp3Tm.Ht8JAsPmgAUe	t	t	073504287	personer/nidal_kahoul.jpg	\N	nidal_kahoul	\N	\N	2026-05-03 11:33:29.653225+02	2026-05-03 11:33:29.653225+02	offline	\N
patryk_jaromin	jaromin.patryk@hotmail.com	jaromin.patryk@hotmail.com	Patryk	Jaromin	worker	$2a$12$dNbJhTuQnOcvKm09L.Sh7e03ytpxMEdXxsOuyU33xCHtVC6w3R4fG	t	t	0707209595	personer/patryk_jaromin.jpg	\N	patryk_jaromin	\N	\N	2026-05-03 11:33:29.837372+02	2026-05-03 11:33:29.837372+02	offline	\N
tero_toikkanen	tero.toikkanen@stuckbema.se	tero.toikkanen@stuckbema.se	Tero	Toikkanen	worker	$2a$12$ilblfbaSxmDgE5255I4UT.3bXpzQNgHtBxsxCGKTH9vCNbJ1as8mK	t	t	0705874547	personer/tero_toikkanen.jpg	\N	tero_toikkanen	\N	\N	2026-05-03 11:33:30.02215+02	2026-05-03 11:33:30.02215+02	offline	\N
tina_bincofiore	tina_bincofiore@stuckbema.local	tina_bincofiore@stuckbema.local	Tina	Bincofiore	worker	$2a$12$epvMfwPC4ubso3/sZp/cfuPoblLhosIpPiTCIc3.mOQc4ShTQxio.	t	t	\N	\N	\N	tina_bincofiore	\N	\N	2026-05-03 11:33:30.206488+02	2026-05-03 11:33:30.206488+02	offline	\N
waclaw_mazuga	waclaw.mazuga@gmail.com	waclaw.mazuga@gmail.com	Waclaw	Mazuga	worker	$2a$12$jPELSTnSEDTHywXEqx.Ag..RzLgNz0NddtKzOkK6E1As5DSov/wX6	t	t	0762732347	personer/waclaw_mazuga.jpg	\N	waclaw_mazuga	\N	\N	2026-05-03 11:33:30.390728+02	2026-05-03 11:33:30.390728+02	offline	\N
jacob_alm	jacob@stuckbema.se	jacob@stuckbema.se	Jacob	Alm	driver	$2a$12$jRllmUviXnlUoyqT.BKc6.fAS5ntWIYj2a23a2k6rhGBNac/nqr5u	t	t	0709767354	personer/jacob_alm.jpg	\N	jacob_alm	\N	\N	2026-05-03 11:33:25.959566+02	2026-05-05 09:29:44.591701+02	offline	2026-05-05 09:29:44.591701+02
\.


--
-- Data for Name: work_order_delivery_events; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.work_order_delivery_events (id, work_order_number, order_id, item_index, artikel, delivered_antal, delivered_at, delivered_by, created_at) FROM stdin;
wode_5a1e378f-fa5c-401b-84dc-dd24115a7170	AO-CODEX-TEST	ord_0905e7dc-992d-4ace-9635-355f7a0fc6a7	0	Kontraktstest artikel	1	2026-05-05 17:43:08.733514+02	usr_9c9db338-10c0-468b-b93b-5f27319bb5a1	2026-05-05 17:43:08.733514+02
\.


--
-- Name: order_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.order_items_id_seq', 46, true);


--
-- Name: push_subscriptions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.push_subscriptions_id_seq', 1, false);


--
-- Name: external_work_orders external_work_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.external_work_orders
    ADD CONSTRAINT external_work_orders_pkey PRIMARY KEY (work_order_number);


--
-- Name: order_items order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_pkey PRIMARY KEY (id);


--
-- Name: orders orders_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_pkey PRIMARY KEY (id);


--
-- Name: people people_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.people
    ADD CONSTRAINT people_pkey PRIMARY KEY (id);


--
-- Name: push_subscriptions push_subscriptions_endpoint_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.push_subscriptions
    ADD CONSTRAINT push_subscriptions_endpoint_key UNIQUE (endpoint);


--
-- Name: push_subscriptions push_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.push_subscriptions
    ADD CONSTRAINT push_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY (id);


--
-- Name: tracking_links tracking_links_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.tracking_links
    ADD CONSTRAINT tracking_links_pkey PRIMARY KEY (token);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_email_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key_key UNIQUE (email_key);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: work_order_delivery_events work_order_delivery_events_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.work_order_delivery_events
    ADD CONSTRAINT work_order_delivery_events_pkey PRIMARY KEY (id);


--
-- Name: idx_order_items_work_order_number; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_order_items_work_order_number ON public.order_items USING btree (work_order_number);


--
-- Name: idx_orders_assigned_driver_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_orders_assigned_driver_id ON public.orders USING btree (assigned_driver_id);


--
-- Name: idx_orders_created_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_orders_created_by ON public.orders USING btree (created_by);


--
-- Name: idx_orders_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_orders_status ON public.orders USING btree (status);


--
-- Name: idx_orders_tracking_token; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_orders_tracking_token ON public.orders USING btree (tracking_token);


--
-- Name: idx_people_search_email; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_people_search_email ON public.people USING btree (lower(COALESCE(email, ''::text)));


--
-- Name: idx_people_search_name; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_people_search_name ON public.people USING btree (lower(name));


--
-- Name: idx_people_search_phone_normalized; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_people_search_phone_normalized ON public.people USING btree (lower(regexp_replace(COALESCE(phone, ''::text), '[^0-9+]'::text, ''::text, 'g'::text)));


--
-- Name: idx_push_subscriptions_enabled; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_push_subscriptions_enabled ON public.push_subscriptions USING btree (enabled);


--
-- Name: idx_push_subscriptions_endpoint_unique; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX idx_push_subscriptions_endpoint_unique ON public.push_subscriptions USING btree (endpoint) WHERE ((endpoint IS NOT NULL) AND (endpoint <> ''::text));


--
-- Name: idx_push_subscriptions_platform; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_push_subscriptions_platform ON public.push_subscriptions USING btree (platform);


--
-- Name: idx_push_subscriptions_provider; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_push_subscriptions_provider ON public.push_subscriptions USING btree (provider);


--
-- Name: idx_push_subscriptions_token; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_push_subscriptions_token ON public.push_subscriptions USING btree (token);


--
-- Name: idx_push_subscriptions_token_unique; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX idx_push_subscriptions_token_unique ON public.push_subscriptions USING btree (token) WHERE ((token IS NOT NULL) AND (token <> ''::text));


--
-- Name: idx_push_subscriptions_user_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_push_subscriptions_user_id ON public.push_subscriptions USING btree (user_id);


--
-- Name: idx_tracking_links_order_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_tracking_links_order_id ON public.tracking_links USING btree (order_id);


--
-- Name: idx_users_active; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_users_active ON public.users USING btree (active);


--
-- Name: idx_users_role; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_users_role ON public.users USING btree (role);


--
-- Name: idx_users_search_email; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_users_search_email ON public.users USING btree (lower(email));


--
-- Name: idx_users_search_name; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_users_search_name ON public.users USING btree (lower(((first_name || ' '::text) || last_name)));


--
-- Name: idx_users_search_phone_normalized; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_users_search_phone_normalized ON public.users USING btree (lower(regexp_replace(COALESCE(phone, ''::text), '[^0-9+]'::text, ''::text, 'g'::text)));


--
-- Name: order_items order_items_order_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.order_items
    ADD CONSTRAINT order_items_order_id_fkey FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;


--
-- Name: orders orders_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: orders orders_delivered_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_delivered_by_fkey FOREIGN KEY (delivered_by) REFERENCES public.users(id);


--
-- Name: orders orders_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.orders
    ADD CONSTRAINT orders_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: people people_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.people
    ADD CONSTRAINT people_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: push_subscriptions push_subscriptions_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.push_subscriptions
    ADD CONSTRAINT push_subscriptions_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: TABLE external_work_orders; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.external_work_orders TO stuckbema_app;


--
-- Name: TABLE order_items; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.order_items TO stuckbema_app;


--
-- Name: SEQUENCE order_items_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.order_items_id_seq TO stuckbema_app;


--
-- Name: TABLE orders; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.orders TO stuckbema_app;


--
-- Name: TABLE people; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.people TO stuckbema_app;


--
-- Name: TABLE push_subscriptions; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.push_subscriptions TO stuckbema_app;


--
-- Name: SEQUENCE push_subscriptions_id_seq; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON SEQUENCE public.push_subscriptions_id_seq TO stuckbema_app;


--
-- Name: TABLE system_settings; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.system_settings TO stuckbema_app;


--
-- Name: TABLE tracking_links; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.tracking_links TO stuckbema_app;


--
-- Name: TABLE users; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.users TO stuckbema_app;


--
-- Name: TABLE work_order_delivery_events; Type: ACL; Schema: public; Owner: postgres
--

GRANT ALL ON TABLE public.work_order_delivery_events TO stuckbema_app;


--
-- Name: DEFAULT PRIVILEGES FOR SEQUENCES; Type: DEFAULT ACL; Schema: public; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON SEQUENCES TO stuckbema_app;


--
-- Name: DEFAULT PRIVILEGES FOR TABLES; Type: DEFAULT ACL; Schema: public; Owner: postgres
--

ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public GRANT ALL ON TABLES TO stuckbema_app;


--
-- PostgreSQL database dump complete
--

\unrestrict cqlgEx4B13e7KTEHO87p8h0AMBvMTpW095z09Bpny8sLr8uUYa7bfBVUS0Sh48e

