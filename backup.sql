--
-- PostgreSQL database dump
--

\restrict 41jcNQgcMZ570icxzyTah7HAghDGMzTAPczlduOECKWsGPCoHQUdWdzufInUvY5

-- Dumped from database version 16.11 (Ubuntu 16.11-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.11 (Ubuntu 16.11-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: account_type; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.account_type AS ENUM (
    'asset',
    'liability',
    'equity',
    'revenue',
    'expense'
);


ALTER TYPE public.account_type OWNER TO postgres;

--
-- Name: fraud_status; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.fraud_status AS ENUM (
    'unchecked',
    'passed',
    'failed',
    'manual_review'
);


ALTER TYPE public.fraud_status OWNER TO postgres;

--
-- Name: kyc_status; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.kyc_status AS ENUM (
    'pending',
    'approved',
    'rejected',
    'expired'
);


ALTER TYPE public.kyc_status OWNER TO postgres;

--
-- Name: ledger_type; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.ledger_type AS ENUM (
    'customer',
    'escrow',
    'treasury',
    'fee',
    'settlement'
);


ALTER TYPE public.ledger_type OWNER TO postgres;

--
-- Name: swap_status; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.swap_status AS ENUM (
    'pending',
    'processing',
    'completed',
    'failed',
    'cancelled'
);


ALTER TYPE public.swap_status OWNER TO postgres;

--
-- Name: enforce_balanced_journal(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.enforce_balanced_journal() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    total NUMERIC(20,4);
BEGIN
    -- Calculate the net balance of the entire journal
    -- If a row has both Debit and Credit, they cancel each other out
    SELECT SUM(
        (CASE WHEN debit_account IS NOT NULL THEN amount ELSE 0 END) - 
        (CASE WHEN credit_account IS NOT NULL THEN amount ELSE 0 END)
    )
    INTO total
    FROM swap_ledger
    WHERE journal_id = NEW.journal_id;

    -- Allowing a tiny margin for floating point errors, or strict 0
    IF total <> 0 THEN
        RAISE EXCEPTION 'Journal % is not balanced. Current net: %', NEW.journal_id, total;
    END IF;

    RETURN NEW;
END;
$$;


ALTER FUNCTION public.enforce_balanced_journal() OWNER TO postgres;

--
-- Name: load_participants_from_json(text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.load_participants_from_json(json_file_path text) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    json_content TEXT;
    json_data JSONB;
    participant_key TEXT;
    participant_data JSONB;
    inserted_count INT := 0;
BEGIN
    -- Read the JSON file
    BEGIN
        json_content := pg_read_file(json_file_path, 0, 1000000);
    EXCEPTION WHEN OTHERS THEN
        RETURN 'Error reading file: ' || SQLERRM;
    END;
    
    -- Parse JSON
    BEGIN
        json_data := json_content::JSONB;
    EXCEPTION WHEN OTHERS THEN
        RETURN 'Error parsing JSON: ' || SQLERRM;
    END;
    
    -- Check if it has the expected structure
    IF json_data ? 'participants' THEN
        -- Loop through each participant
        FOR participant_key, participant_data IN SELECT * FROM jsonb_each(json_data->'participants')
        LOOP
            INSERT INTO participants (
                name,
                type,
                category,
                provider_code,
                auth_type,
                base_url,
                system_user_id,
                legal_entity_identifier,
                license_number,
                settlement_account,
                settlement_type,
                status,
                capabilities,
                resource_endpoints,
                phone_format,
                security_config,
                message_profile,
                routing_info
            ) VALUES (
                participant_key,
                participant_data->>'type',
                participant_data->>'category',
                participant_data->>'provider_code',
                participant_data->>'auth_type',
                participant_data->>'base_url',
                (participant_data->'identity'->>'system_user_id')::BIGINT,
                participant_data->'identity'->>'legal_entity_identifier',
                participant_data->'identity'->>'license_number',
                participant_data->'routing'->>'settlement_account',
                participant_data->'routing'->>'settlement_type',
                COALESCE(participant_data->>'status', 'ACTIVE'),
                participant_data->'capabilities',
                participant_data->'resource_endpoints',
                participant_data->'phone_format',
                participant_data->'security',
                participant_data->'message_profile',
                participant_data->'routing'
            )
            ON CONFLICT (name) DO UPDATE SET
                type = EXCLUDED.type,
                category = EXCLUDED.category,
                provider_code = EXCLUDED.provider_code,
                auth_type = EXCLUDED.auth_type,
                base_url = EXCLUDED.base_url,
                system_user_id = EXCLUDED.system_user_id,
                legal_entity_identifier = EXCLUDED.legal_entity_identifier,
                license_number = EXCLUDED.license_number,
                settlement_account = EXCLUDED.settlement_account,
                settlement_type = EXCLUDED.settlement_type,
                status = EXCLUDED.status,
                capabilities = EXCLUDED.capabilities,
                resource_endpoints = EXCLUDED.resource_endpoints,
                phone_format = EXCLUDED.phone_format,
                security_config = EXCLUDED.security_config,
                message_profile = EXCLUDED.message_profile,
                routing_info = EXCLUDED.routing_info,
                updated_at = CURRENT_TIMESTAMP;
                
            inserted_count := inserted_count + 1;
        END LOOP;
        
        RETURN format('Loaded %s participants successfully.', inserted_count);
    ELSE
        RETURN 'Invalid JSON format: missing "participants" key';
    END IF;
END;
$$;


ALTER FUNCTION public.load_participants_from_json(json_file_path text) OWNER TO postgres;

--
-- Name: prevent_hard_delete(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.prevent_hard_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  RAISE EXCEPTION 'Hard deletes are forbidden on financial records per BoB/ECB standards';
END;
$$;


ALTER FUNCTION public.prevent_hard_delete() OWNER TO postgres;

--
-- Name: update_updated_at_column(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.update_updated_at_column() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.update_updated_at_column() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: account_freezes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.account_freezes (
    freeze_id integer NOT NULL,
    account_id integer NOT NULL,
    reason text,
    frozen_by integer,
    start_time timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    end_time timestamp without time zone
);


ALTER TABLE public.account_freezes OWNER TO postgres;

--
-- Name: account_freezes_freeze_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.account_freezes_freeze_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.account_freezes_freeze_id_seq OWNER TO postgres;

--
-- Name: account_freezes_freeze_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.account_freezes_freeze_id_seq OWNED BY public.account_freezes.freeze_id;


--
-- Name: accounting_closures; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.accounting_closures (
    closure_date date NOT NULL,
    closed_by integer,
    closed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    closure_type character varying(20),
    remarks text,
    CONSTRAINT accounting_closures_closure_type_check CHECK (((closure_type)::text = ANY ((ARRAY['EOD'::character varying, 'EOM'::character varying, 'EOY'::character varying])::text[])))
);


ALTER TABLE public.accounting_closures OWNER TO postgres;

--
-- Name: accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.accounts (
    account_id integer NOT NULL,
    user_id integer NOT NULL,
    account_number character varying(255),
    account_type character varying(50) DEFAULT 'checking'::character varying,
    balance numeric(20,4) DEFAULT 0.00,
    currency character varying(10) DEFAULT 'USD'::character varying,
    status character varying(50) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.accounts OWNER TO postgres;

--
-- Name: accounts_account_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.accounts_account_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.accounts_account_id_seq OWNER TO postgres;

--
-- Name: accounts_account_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.accounts_account_id_seq OWNED BY public.accounts.account_id;


--
-- Name: api_keys; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.api_keys (
    id bigint NOT NULL,
    client_name character varying(100) NOT NULL,
    api_key character varying(255) NOT NULL,
    active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    status character varying(50) DEFAULT 'ACTIVE'::character varying
);


ALTER TABLE public.api_keys OWNER TO postgres;

--
-- Name: api_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.api_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.api_keys_id_seq OWNER TO postgres;

--
-- Name: api_keys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.api_keys_id_seq OWNED BY public.api_keys.id;


--
-- Name: api_message_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.api_message_logs (
    log_id bigint NOT NULL,
    message_id character varying(100) NOT NULL,
    message_type character varying(50) NOT NULL,
    direction character varying(10) NOT NULL,
    participant_id bigint,
    participant_name character varying(100),
    endpoint character varying(255),
    request_payload jsonb,
    response_payload jsonb,
    http_status_code integer,
    curl_error text,
    success boolean DEFAULT false,
    duration_ms integer,
    retry_count integer DEFAULT 0,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    processed_at timestamp with time zone
);


ALTER TABLE public.api_message_logs OWNER TO postgres;

--
-- Name: api_message_logs_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.api_message_logs_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.api_message_logs_log_id_seq OWNER TO postgres;

--
-- Name: api_message_logs_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.api_message_logs_log_id_seq OWNED BY public.api_message_logs.log_id;


--
-- Name: atm_dispenses; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.atm_dispenses (
    id integer NOT NULL,
    atm_id character varying(50) NOT NULL,
    trace_number character varying(255) NOT NULL,
    amount numeric(20,4) NOT NULL,
    currency character varying(10) DEFAULT 'BWP'::character varying,
    status character varying(50) DEFAULT 'DISPENSED'::character varying,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.atm_dispenses OWNER TO postgres;

--
-- Name: atm_dispenses_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.atm_dispenses_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.atm_dispenses_id_seq OWNER TO postgres;

--
-- Name: atm_dispenses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.atm_dispenses_id_seq OWNED BY public.atm_dispenses.id;


--
-- Name: atm_requests; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.atm_requests (
    atm_request_id integer NOT NULL,
    atm_id character varying(50) NOT NULL,
    trace_number character varying(255) NOT NULL,
    request_time timestamp without time zone DEFAULT now(),
    status character varying(50) DEFAULT 'PENDING'::character varying,
    response jsonb,
    swap_number character varying(255),
    swap_pin character varying(20),
    user_phone character varying(20)
);


ALTER TABLE public.atm_requests OWNER TO postgres;

--
-- Name: atm_requests_atm_request_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.atm_requests_atm_request_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.atm_requests_atm_request_id_seq OWNER TO postgres;

--
-- Name: atm_requests_atm_request_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.atm_requests_atm_request_id_seq OWNED BY public.atm_requests.atm_request_id;


--
-- Name: atm_terminals; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.atm_terminals (
    atm_id character varying(50) NOT NULL,
    location text,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    total_cash numeric(20,4) DEFAULT 0,
    reserved_cash numeric(20,4) DEFAULT 0,
    last_sync timestamp without time zone
);


ALTER TABLE public.atm_terminals OWNER TO postgres;

--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.audit_logs (
    id integer NOT NULL,
    entity character varying(255) NOT NULL,
    entity_id integer NOT NULL,
    action character varying(255) NOT NULL,
    category character varying(50) DEFAULT 'system'::character varying,
    severity character varying(50) DEFAULT 'info'::character varying,
    old_value text,
    new_value text,
    performed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    performed_by integer NOT NULL,
    ip_address character varying(50),
    user_agent text,
    geo_location character varying(255)
);


ALTER TABLE public.audit_logs OWNER TO postgres;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.audit_logs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.audit_logs_id_seq OWNER TO postgres;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.audit_logs_id_seq OWNED BY public.audit_logs.id;


--
-- Name: cashouts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cashouts (
    cashout_id integer NOT NULL,
    trace_number character varying(255) NOT NULL,
    cashout_reference character varying(255) NOT NULL,
    destination_bank_id integer NOT NULL,
    atm_id character varying(50),
    user_id integer NOT NULL,
    amount numeric(20,4) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    status character varying(50) DEFAULT 'READY'::character varying,
    created_at timestamp without time zone DEFAULT now(),
    dispensed_at timestamp without time zone,
    swap_number character varying(255),
    swap_pin character varying(20),
    user_phone character varying(20),
    agent_id integer,
    source_bank_id integer
);


ALTER TABLE public.cashouts OWNER TO postgres;

--
-- Name: cashouts_cashout_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.cashouts_cashout_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.cashouts_cashout_id_seq OWNER TO postgres;

--
-- Name: cashouts_cashout_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.cashouts_cashout_id_seq OWNED BY public.cashouts.cashout_id;


--
-- Name: central_bank_link; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.central_bank_link (
    id integer NOT NULL,
    bank_id integer NOT NULL,
    link_status character varying(50) DEFAULT 'connected'::character varying,
    last_sync timestamp without time zone
);


ALTER TABLE public.central_bank_link OWNER TO postgres;

--
-- Name: central_bank_link_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.central_bank_link_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.central_bank_link_id_seq OWNER TO postgres;

--
-- Name: central_bank_link_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.central_bank_link_id_seq OWNED BY public.central_bank_link.id;


--
-- Name: chart_of_accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.chart_of_accounts (
    coa_code character varying(20) NOT NULL,
    coa_name character varying(255) NOT NULL,
    coa_type character varying(20),
    parent_coa_code character varying(20),
    is_customer_account boolean DEFAULT false,
    is_trust_account boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chart_of_accounts_coa_type_check CHECK (((coa_type)::text = ANY ((ARRAY['asset'::character varying, 'liability'::character varying, 'equity'::character varying, 'income'::character varying, 'expense'::character varying])::text[])))
);


ALTER TABLE public.chart_of_accounts OWNER TO postgres;

--
-- Name: data_retention_policies; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.data_retention_policies (
    entity_name character varying(100) NOT NULL,
    retention_years integer NOT NULL,
    legal_basis text,
    last_reviewed date
);


ALTER TABLE public.data_retention_policies OWNER TO postgres;

--
-- Name: disaster_recovery_tests; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.disaster_recovery_tests (
    test_id bigint NOT NULL,
    test_date date NOT NULL,
    test_type character varying(50),
    systems_tested text[],
    result character varying(20),
    issues_found text,
    resolved boolean DEFAULT false,
    signed_off_by integer,
    CONSTRAINT disaster_recovery_tests_result_check CHECK (((result)::text = ANY ((ARRAY['pass'::character varying, 'fail'::character varying, 'partial'::character varying])::text[])))
);


ALTER TABLE public.disaster_recovery_tests OWNER TO postgres;

--
-- Name: disaster_recovery_tests_test_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.disaster_recovery_tests_test_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.disaster_recovery_tests_test_id_seq OWNER TO postgres;

--
-- Name: disaster_recovery_tests_test_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.disaster_recovery_tests_test_id_seq OWNED BY public.disaster_recovery_tests.test_id;


--
-- Name: external_banks; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.external_banks (
    id integer NOT NULL,
    user_id integer NOT NULL,
    bank_name character varying(255) NOT NULL,
    account_number character varying(255) NOT NULL
);


ALTER TABLE public.external_banks OWNER TO postgres;

--
-- Name: external_banks_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.external_banks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.external_banks_id_seq OWNER TO postgres;

--
-- Name: external_banks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.external_banks_id_seq OWNED BY public.external_banks.id;


--
-- Name: hold_transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.hold_transactions (
    hold_id bigint NOT NULL,
    hold_reference character varying(100) NOT NULL,
    swap_reference character varying(100) NOT NULL,
    participant_id bigint,
    participant_name character varying(100),
    asset_type character varying(50) NOT NULL,
    amount numeric(20,8) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    hold_expiry timestamp with time zone,
    source_details jsonb,
    destination_institution character varying(100),
    destination_participant_id bigint,
    metadata jsonb,
    placed_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    released_at timestamp with time zone,
    debited_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.hold_transactions OWNER TO postgres;

--
-- Name: hold_transactions_hold_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hold_transactions_hold_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hold_transactions_hold_id_seq OWNER TO postgres;

--
-- Name: hold_transactions_hold_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hold_transactions_hold_id_seq OWNED BY public.hold_transactions.hold_id;


--
-- Name: idempotency_keys; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.idempotency_keys (
    key_value character varying(255) NOT NULL,
    response jsonb,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.idempotency_keys OWNER TO postgres;

--
-- Name: incoming_pre_advice; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.incoming_pre_advice (
    id integer NOT NULL,
    trace_number character varying(255) NOT NULL,
    issuer_bank_id integer NOT NULL,
    destination_bank_id integer NOT NULL,
    user_id integer NOT NULL,
    amount numeric(20,4) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    status character varying(50) DEFAULT 'AUTHORIZED'::character varying,
    cashout_reference character varying(255),
    created_at timestamp without time zone DEFAULT now(),
    completed_at timestamp without time zone,
    notified_to_vouchmorph boolean DEFAULT false,
    cashout_created_at timestamp without time zone
);


ALTER TABLE public.incoming_pre_advice OWNER TO postgres;

--
-- Name: incoming_pre_advice_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.incoming_pre_advice_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.incoming_pre_advice_id_seq OWNER TO postgres;

--
-- Name: incoming_pre_advice_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.incoming_pre_advice_id_seq OWNED BY public.incoming_pre_advice.id;


--
-- Name: instant_money_transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.instant_money_transactions (
    transaction_id integer NOT NULL,
    wallet_id integer NOT NULL,
    type character varying(50) NOT NULL,
    amount numeric(15,2) NOT NULL,
    reference character varying(255),
    related_account_id integer,
    status character varying(50) DEFAULT 'completed'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.instant_money_transactions OWNER TO postgres;

--
-- Name: instant_money_transactions_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.instant_money_transactions_transaction_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.instant_money_transactions_transaction_id_seq OWNER TO postgres;

--
-- Name: instant_money_transactions_transaction_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.instant_money_transactions_transaction_id_seq OWNED BY public.instant_money_transactions.transaction_id;


--
-- Name: instant_money_transfers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.instant_money_transfers (
    transfer_id integer NOT NULL,
    from_wallet_id integer NOT NULL,
    to_wallet_id integer NOT NULL,
    amount numeric(15,2) NOT NULL,
    reference character varying(255),
    status character varying(50) DEFAULT 'completed'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.instant_money_transfers OWNER TO postgres;

--
-- Name: instant_money_transfers_transfer_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.instant_money_transfers_transfer_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.instant_money_transfers_transfer_id_seq OWNER TO postgres;

--
-- Name: instant_money_transfers_transfer_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.instant_money_transfers_transfer_id_seq OWNED BY public.instant_money_transfers.transfer_id;


--
-- Name: instant_money_vouchers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.instant_money_vouchers (
    voucher_id integer NOT NULL,
    amount numeric(15,2) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    created_by integer NOT NULL,
    recipient_phone character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    voucher_number character varying(255),
    voucher_pin character varying(255),
    redeemed_by integer,
    voucher_created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    voucher_expires_at timestamp without time zone,
    sat_purchased boolean DEFAULT false,
    sat_fee_paid_by character varying(50) DEFAULT 'sender'::character varying,
    sat_expires_at timestamp without time zone,
    redeemed_at timestamp without time zone,
    swap_made_at timestamp without time zone,
    holding_account character varying(64) DEFAULT 'VOUCHER-SUSPENSE'::character varying,
    status character varying(20) DEFAULT 'hold'::character varying NOT NULL,
    origin character varying(50) DEFAULT 'zurubank'::character varying NOT NULL,
    external_reference character varying(255),
    source_institution character varying(100),
    source_hold_reference character varying(255),
    CONSTRAINT instant_money_vouchers_origin_check CHECK (((origin)::text = ANY ((ARRAY['zurubank'::character varying, 'swap'::character varying])::text[]))),
    CONSTRAINT instant_money_vouchers_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'redeemed'::character varying, 'hold'::character varying])::text[])))
);


ALTER TABLE public.instant_money_vouchers OWNER TO postgres;

--
-- Name: instant_money_vouchers_voucher_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.instant_money_vouchers_voucher_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.instant_money_vouchers_voucher_id_seq OWNER TO postgres;

--
-- Name: instant_money_vouchers_voucher_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.instant_money_vouchers_voucher_id_seq OWNED BY public.instant_money_vouchers.voucher_id;


--
-- Name: instant_money_wallets; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.instant_money_wallets (
    wallet_id integer NOT NULL,
    user_id integer NOT NULL,
    balance numeric(20,4) DEFAULT 0.00,
    currency character(3) DEFAULT 'BWP'::bpchar,
    status character varying(50) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.instant_money_wallets OWNER TO postgres;

--
-- Name: instant_money_wallets_wallet_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.instant_money_wallets_wallet_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.instant_money_wallets_wallet_id_seq OWNER TO postgres;

--
-- Name: instant_money_wallets_wallet_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.instant_money_wallets_wallet_id_seq OWNED BY public.instant_money_wallets.wallet_id;


--
-- Name: interbank_clearing_positions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.interbank_clearing_positions (
    id bigint NOT NULL,
    debtor_bank character varying(50),
    creditor_bank character varying(50),
    amount numeric(20,4),
    currency character(3) DEFAULT 'BWP'::bpchar,
    trace_number character varying(64),
    settlement_status character varying(20) DEFAULT 'PENDING'::character varying,
    business_date date DEFAULT CURRENT_DATE
);


ALTER TABLE public.interbank_clearing_positions OWNER TO postgres;

--
-- Name: interbank_clearing_positions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.interbank_clearing_positions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.interbank_clearing_positions_id_seq OWNER TO postgres;

--
-- Name: interbank_clearing_positions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.interbank_clearing_positions_id_seq OWNED BY public.interbank_clearing_positions.id;


--
-- Name: journals; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.journals (
    journal_id bigint NOT NULL,
    reference character varying(255) NOT NULL,
    description text,
    created_at timestamp without time zone DEFAULT now()
);


ALTER TABLE public.journals OWNER TO postgres;

--
-- Name: journals_journal_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.journals_journal_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.journals_journal_id_seq OWNER TO postgres;

--
-- Name: journals_journal_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.journals_journal_id_seq OWNED BY public.journals.journal_id;


--
-- Name: kyc_profiles; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.kyc_profiles (
    id integer NOT NULL,
    user_id integer NOT NULL,
    kyc_level character varying(20),
    risk_rating character varying(20),
    source_of_funds text NOT NULL,
    pep boolean DEFAULT false,
    sanctions_checked boolean DEFAULT false,
    last_reviewed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT now(),
    CONSTRAINT kyc_profiles_kyc_level_check CHECK (((kyc_level)::text = ANY ((ARRAY['LOW'::character varying, 'MEDIUM'::character varying, 'HIGH'::character varying])::text[]))),
    CONSTRAINT kyc_profiles_risk_rating_check CHECK (((risk_rating)::text = ANY ((ARRAY['LOW'::character varying, 'MEDIUM'::character varying, 'HIGH'::character varying])::text[])))
);


ALTER TABLE public.kyc_profiles OWNER TO postgres;

--
-- Name: kyc_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.kyc_profiles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.kyc_profiles_id_seq OWNER TO postgres;

--
-- Name: kyc_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.kyc_profiles_id_seq OWNED BY public.kyc_profiles.id;


--
-- Name: ledger_accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.ledger_accounts (
    id integer NOT NULL,
    account_name character varying(255) NOT NULL,
    account_number character varying(255) NOT NULL,
    account_type character varying(50) NOT NULL,
    balance numeric(20,4) DEFAULT 0.00 NOT NULL,
    currency character varying(10) DEFAULT 'BWP'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.ledger_accounts OWNER TO postgres;

--
-- Name: ledger_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.ledger_accounts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ledger_accounts_id_seq OWNER TO postgres;

--
-- Name: ledger_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.ledger_accounts_id_seq OWNED BY public.ledger_accounts.id;


--
-- Name: network_authorizations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.network_authorizations (
    id bigint NOT NULL,
    trace_number character varying(64) NOT NULL,
    role character varying(20),
    counterparty_bank character varying(50) NOT NULL,
    amount numeric(20,4) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    token_hash character varying(255),
    auth_code character varying(20),
    status character varying(30) DEFAULT 'AUTHORIZED'::character varying,
    expiry_time timestamp without time zone,
    used_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT network_authorizations_role_check CHECK (((role)::text = ANY ((ARRAY['ISSUER'::character varying, 'ACQUIRER'::character varying])::text[])))
);


ALTER TABLE public.network_authorizations OWNER TO postgres;

--
-- Name: network_authorizations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.network_authorizations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.network_authorizations_id_seq OWNER TO postgres;

--
-- Name: network_authorizations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.network_authorizations_id_seq OWNED BY public.network_authorizations.id;


--
-- Name: participants; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.participants (
    participant_id bigint NOT NULL,
    name character varying(100) NOT NULL,
    type character varying(50),
    category character varying(50),
    provider_code character varying(50),
    auth_type character varying(50),
    base_url text,
    system_user_id bigint,
    legal_entity_identifier character varying(50),
    license_number character varying(50),
    settlement_account character varying(50),
    settlement_type character varying(50),
    status character varying(20) DEFAULT 'ACTIVE'::character varying,
    capabilities jsonb,
    resource_endpoints jsonb,
    phone_format jsonb,
    security_config jsonb,
    message_profile jsonb,
    routing_info jsonb,
    metadata jsonb,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.participants OWNER TO postgres;

--
-- Name: participants_participant_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.participants_participant_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.participants_participant_id_seq OWNER TO postgres;

--
-- Name: participants_participant_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.participants_participant_id_seq OWNED BY public.participants.participant_id;


--
-- Name: processed_deposits; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.processed_deposits (
    id integer NOT NULL,
    deposit_ref character varying(100) NOT NULL,
    account_number character varying(50),
    amount numeric(15,2),
    idempotency_key character varying(255),
    processed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.processed_deposits OWNER TO postgres;

--
-- Name: processed_deposits_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.processed_deposits_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.processed_deposits_id_seq OWNER TO postgres;

--
-- Name: processed_deposits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.processed_deposits_id_seq OWNED BY public.processed_deposits.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sessions (
    session_id integer NOT NULL,
    user_id integer NOT NULL,
    token character varying(255) NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.sessions OWNER TO postgres;

--
-- Name: sessions_session_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.sessions_session_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sessions_session_id_seq OWNER TO postgres;

--
-- Name: sessions_session_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.sessions_session_id_seq OWNED BY public.sessions.session_id;


--
-- Name: settlement_instructions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.settlement_instructions (
    id integer NOT NULL,
    reference character varying(100) NOT NULL,
    debit_ref character varying(100),
    account_number character varying(50),
    amount numeric(15,2),
    type character varying(20),
    status character varying(50) DEFAULT 'PENDING'::character varying,
    idempotency_key character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    processed_at timestamp without time zone
);


ALTER TABLE public.settlement_instructions OWNER TO postgres;

--
-- Name: settlement_instructions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.settlement_instructions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.settlement_instructions_id_seq OWNER TO postgres;

--
-- Name: settlement_instructions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.settlement_instructions_id_seq OWNED BY public.settlement_instructions.id;


--
-- Name: swap_audit; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_audit (
    id integer NOT NULL,
    action_type character varying(50) NOT NULL,
    actor character varying(255),
    reference character varying(255),
    details text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.swap_audit OWNER TO postgres;

--
-- Name: swap_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_audit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_audit_id_seq OWNER TO postgres;

--
-- Name: swap_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_audit_id_seq OWNED BY public.swap_audit.id;


--
-- Name: swap_internal_accounts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_internal_accounts (
    id integer NOT NULL,
    account_code character varying(255) NOT NULL,
    purpose character varying(50) NOT NULL,
    balance numeric(15,2) DEFAULT 0.00,
    currency character(3) DEFAULT 'BWP'::bpchar,
    status character varying(50) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.swap_internal_accounts OWNER TO postgres;

--
-- Name: swap_internal_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_internal_accounts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_internal_accounts_id_seq OWNER TO postgres;

--
-- Name: swap_internal_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_internal_accounts_id_seq OWNED BY public.swap_internal_accounts.id;


--
-- Name: swap_ledger; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_ledger (
    ledger_id integer NOT NULL,
    reference_id character varying(255) NOT NULL,
    debit_account character varying(255) NOT NULL,
    credit_account character varying(255) NOT NULL,
    amount numeric(15,2) NOT NULL,
    currency character(3) DEFAULT 'BWP'::bpchar,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    ref_voucher_id integer,
    is_deleted boolean DEFAULT false,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    journal_id bigint
);


ALTER TABLE public.swap_ledger OWNER TO postgres;

--
-- Name: swap_ledger_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_ledger_ledger_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_ledger_ledger_id_seq OWNER TO postgres;

--
-- Name: swap_ledger_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_ledger_ledger_id_seq OWNED BY public.swap_ledger.ledger_id;


--
-- Name: swap_ledgers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_ledgers (
    ledger_id bigint NOT NULL,
    swap_reference character varying(255) NOT NULL,
    from_participant character varying(255) NOT NULL,
    to_participant character varying(255) NOT NULL,
    from_type character varying(255) NOT NULL,
    to_type character varying(255) NOT NULL,
    from_account character varying(255),
    to_account character varying(255),
    original_amount numeric(15,2) DEFAULT 0.00 NOT NULL,
    final_amount numeric(15,2) DEFAULT 0.00 NOT NULL,
    currency_code character varying(10) DEFAULT 'BWP'::character varying NOT NULL,
    swap_fee numeric(15,2) DEFAULT 0.00 NOT NULL,
    creation_fee numeric(15,2) DEFAULT 0.00 NOT NULL,
    admin_fee numeric(15,2) DEFAULT 0.00 NOT NULL,
    sms_fee numeric(15,2) DEFAULT 0.00 NOT NULL,
    token character varying(255),
    status character varying(50) DEFAULT 'completed'::character varying NOT NULL,
    reverse_logic boolean DEFAULT false NOT NULL,
    performed_by integer DEFAULT 1 NOT NULL,
    notes text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.swap_ledgers OWNER TO postgres;

--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_ledgers_ledger_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_ledgers_ledger_id_seq OWNER TO postgres;

--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_ledgers_ledger_id_seq OWNED BY public.swap_ledgers.ledger_id;


--
-- Name: swap_linked_banks; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_linked_banks (
    id integer NOT NULL,
    bank_code character varying(50) NOT NULL,
    bank_name character varying(255) NOT NULL,
    api_endpoint character varying(255),
    public_key text,
    status character varying(50) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.swap_linked_banks OWNER TO postgres;

--
-- Name: swap_linked_banks_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_linked_banks_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_linked_banks_id_seq OWNER TO postgres;

--
-- Name: swap_linked_banks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_linked_banks_id_seq OWNED BY public.swap_linked_banks.id;


--
-- Name: swap_middleman; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_middleman (
    id integer NOT NULL,
    account_number character varying(255) NOT NULL,
    api_key character varying(255) NOT NULL,
    webhook_url character varying(255),
    encryption_key character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.swap_middleman OWNER TO postgres;

--
-- Name: swap_middleman_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_middleman_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_middleman_id_seq OWNER TO postgres;

--
-- Name: swap_middleman_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_middleman_id_seq OWNED BY public.swap_middleman.id;


--
-- Name: swap_requests; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_requests (
    swap_id bigint NOT NULL,
    swap_uuid character varying(100) DEFAULT gen_random_uuid(),
    user_id bigint NOT NULL,
    from_currency character(3) NOT NULL,
    to_currency character(3) NOT NULL,
    amount numeric(20,8) NOT NULL,
    converted_amount numeric(20,8),
    exchange_rate numeric(20,8) DEFAULT 1,
    fee_amount numeric(20,8) DEFAULT 0,
    total_amount numeric(20,8),
    status public.swap_status DEFAULT 'pending'::public.swap_status,
    fraud_check_status public.fraud_status DEFAULT 'unchecked'::public.fraud_status,
    processor_reference character varying(255),
    metadata jsonb DEFAULT '{}'::jsonb,
    completed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.swap_requests OWNER TO postgres;

--
-- Name: swap_requests_swap_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_requests_swap_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_requests_swap_id_seq OWNER TO postgres;

--
-- Name: swap_requests_swap_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_requests_swap_id_seq OWNED BY public.swap_requests.swap_id;


--
-- Name: swap_settings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_settings (
    id integer NOT NULL,
    setting_key character varying(255) NOT NULL,
    setting_value character varying(255) NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.swap_settings OWNER TO postgres;

--
-- Name: swap_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_settings_id_seq OWNER TO postgres;

--
-- Name: swap_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_settings_id_seq OWNED BY public.swap_settings.id;


--
-- Name: swap_transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.swap_transactions (
    id integer NOT NULL,
    middleman_id integer,
    source character varying(255) NOT NULL,
    destination character varying(255),
    type character varying(50) NOT NULL,
    amount numeric(15,2) NOT NULL,
    reference character varying(255),
    status character varying(50) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.swap_transactions OWNER TO postgres;

--
-- Name: swap_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.swap_transactions_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.swap_transactions_id_seq OWNER TO postgres;

--
-- Name: swap_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.swap_transactions_id_seq OWNED BY public.swap_transactions.id;


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.system_settings (
    setting_key character varying(255) NOT NULL,
    setting_value character varying(255),
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.system_settings OWNER TO postgres;

--
-- Name: transaction_log_view; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.transaction_log_view AS
 SELECT COALESCE(ht.swap_reference, aml.message_id) AS transaction_id,
    ht.hold_reference,
    ht.status AS hold_status,
    ht.placed_at AS hold_placed_at,
    ht.debited_at,
    ht.amount AS hold_amount,
    ht.asset_type,
    p.name AS participant_name,
    p.provider_code,
    p.type AS participant_type,
    aml.message_type,
    aml.success AS api_success,
    aml.http_status_code,
    aml.created_at AS api_called_at,
    aml.endpoint,
    aml.direction
   FROM ((public.hold_transactions ht
     FULL JOIN public.api_message_logs aml ON (((ht.swap_reference)::text = (aml.message_id)::text)))
     LEFT JOIN public.participants p ON ((COALESCE(ht.participant_id, aml.participant_id) = p.participant_id)));


ALTER VIEW public.transaction_log_view OWNER TO postgres;

--
-- Name: transaction_reversals; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.transaction_reversals (
    id integer NOT NULL,
    original_trace character varying(100) NOT NULL,
    reversal_trace character varying(100) NOT NULL,
    reason text,
    reversed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.transaction_reversals OWNER TO postgres;

--
-- Name: transaction_reversals_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.transaction_reversals_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transaction_reversals_id_seq OWNER TO postgres;

--
-- Name: transaction_reversals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.transaction_reversals_id_seq OWNED BY public.transaction_reversals.id;


--
-- Name: transactions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.transactions (
    transaction_id integer NOT NULL,
    user_id integer DEFAULT 0,
    account_id integer DEFAULT 0 NOT NULL,
    from_account character varying(255),
    to_account character varying(255),
    type character varying(50) NOT NULL,
    amount numeric(15,2) DEFAULT 0.00 NOT NULL,
    reference character varying(255),
    description text,
    status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    swap_fee numeric(15,2) DEFAULT 0.00,
    creation_fee numeric(15,2) DEFAULT 0.00,
    admin_fee numeric(15,2) DEFAULT 0.00,
    sms_fee numeric(15,2) DEFAULT 0.00,
    rounding_adjustment numeric(15,2) DEFAULT 0.00,
    is_deleted boolean DEFAULT false,
    is_large_transaction boolean DEFAULT false,
    is_suspicious boolean DEFAULT false,
    reported_to_regulator boolean DEFAULT false,
    regulator_report_reference character varying(255),
    trace_number character varying(255)
);


ALTER TABLE public.transactions OWNER TO postgres;

--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.transactions_transaction_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.transactions_transaction_id_seq OWNER TO postgres;

--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.transactions_transaction_id_seq OWNED BY public.transactions.transaction_id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    user_id integer NOT NULL,
    full_name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    password_hash character varying(255) NOT NULL,
    phone character varying(255),
    role character varying(50) DEFAULT 'customer'::character varying,
    status character varying(50) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    password_changed_at timestamp without time zone,
    failed_login_attempts integer DEFAULT 0,
    last_failed_login timestamp without time zone
);


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_user_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_user_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_user_id_seq OWNER TO postgres;

--
-- Name: users_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_user_id_seq OWNED BY public.users.user_id;


--
-- Name: voucher_cashout_details; Type: TABLE; Schema: public; Owner: swap_admin
--

CREATE TABLE public.voucher_cashout_details (
    id integer NOT NULL,
    voucher_number character varying(255) NOT NULL,
    auth_code character varying(50) NOT NULL,
    qr_code text,
    barcode character varying(255),
    amount numeric(20,4) NOT NULL,
    currency character varying(10) DEFAULT 'BWP'::character varying,
    recipient_phone character varying(50),
    instructions text,
    expires_at timestamp without time zone NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    redeemed_at timestamp without time zone,
    redeemed_by_user_id integer,
    redeemed_by_atm character varying(100),
    redeemed_by_agent character varying(100)
);


ALTER TABLE public.voucher_cashout_details OWNER TO swap_admin;

--
-- Name: voucher_cashout_details_id_seq; Type: SEQUENCE; Schema: public; Owner: swap_admin
--

CREATE SEQUENCE public.voucher_cashout_details_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.voucher_cashout_details_id_seq OWNER TO swap_admin;

--
-- Name: voucher_cashout_details_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: swap_admin
--

ALTER SEQUENCE public.voucher_cashout_details_id_seq OWNED BY public.voucher_cashout_details.id;


--
-- Name: wallet_locks; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.wallet_locks (
    id bigint NOT NULL,
    wallet_id integer NOT NULL,
    trace_number character varying(64) NOT NULL,
    amount numeric(20,4) NOT NULL,
    status character varying(20) DEFAULT 'LOCKED'::character varying,
    expires_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.wallet_locks OWNER TO postgres;

--
-- Name: wallet_locks_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.wallet_locks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.wallet_locks_id_seq OWNER TO postgres;

--
-- Name: wallet_locks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.wallet_locks_id_seq OWNED BY public.wallet_locks.id;


--
-- Name: zurubank_middleman; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.zurubank_middleman (
    id integer NOT NULL,
    account_number character varying(255) NOT NULL,
    api_key character varying(255) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.zurubank_middleman OWNER TO postgres;

--
-- Name: zurubank_middleman_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.zurubank_middleman_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.zurubank_middleman_id_seq OWNER TO postgres;

--
-- Name: zurubank_middleman_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.zurubank_middleman_id_seq OWNED BY public.zurubank_middleman.id;


--
-- Name: account_freezes freeze_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.account_freezes ALTER COLUMN freeze_id SET DEFAULT nextval('public.account_freezes_freeze_id_seq'::regclass);


--
-- Name: accounts account_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts ALTER COLUMN account_id SET DEFAULT nextval('public.accounts_account_id_seq'::regclass);


--
-- Name: api_keys id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_keys ALTER COLUMN id SET DEFAULT nextval('public.api_keys_id_seq'::regclass);


--
-- Name: api_message_logs log_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_message_logs ALTER COLUMN log_id SET DEFAULT nextval('public.api_message_logs_log_id_seq'::regclass);


--
-- Name: atm_dispenses id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_dispenses ALTER COLUMN id SET DEFAULT nextval('public.atm_dispenses_id_seq'::regclass);


--
-- Name: atm_requests atm_request_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_requests ALTER COLUMN atm_request_id SET DEFAULT nextval('public.atm_requests_atm_request_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN id SET DEFAULT nextval('public.audit_logs_id_seq'::regclass);


--
-- Name: cashouts cashout_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cashouts ALTER COLUMN cashout_id SET DEFAULT nextval('public.cashouts_cashout_id_seq'::regclass);


--
-- Name: central_bank_link id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.central_bank_link ALTER COLUMN id SET DEFAULT nextval('public.central_bank_link_id_seq'::regclass);


--
-- Name: disaster_recovery_tests test_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.disaster_recovery_tests ALTER COLUMN test_id SET DEFAULT nextval('public.disaster_recovery_tests_test_id_seq'::regclass);


--
-- Name: external_banks id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.external_banks ALTER COLUMN id SET DEFAULT nextval('public.external_banks_id_seq'::regclass);


--
-- Name: hold_transactions hold_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions ALTER COLUMN hold_id SET DEFAULT nextval('public.hold_transactions_hold_id_seq'::regclass);


--
-- Name: incoming_pre_advice id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.incoming_pre_advice ALTER COLUMN id SET DEFAULT nextval('public.incoming_pre_advice_id_seq'::regclass);


--
-- Name: instant_money_transactions transaction_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_transactions ALTER COLUMN transaction_id SET DEFAULT nextval('public.instant_money_transactions_transaction_id_seq'::regclass);


--
-- Name: instant_money_transfers transfer_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_transfers ALTER COLUMN transfer_id SET DEFAULT nextval('public.instant_money_transfers_transfer_id_seq'::regclass);


--
-- Name: instant_money_vouchers voucher_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_vouchers ALTER COLUMN voucher_id SET DEFAULT nextval('public.instant_money_vouchers_voucher_id_seq'::regclass);


--
-- Name: instant_money_wallets wallet_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_wallets ALTER COLUMN wallet_id SET DEFAULT nextval('public.instant_money_wallets_wallet_id_seq'::regclass);


--
-- Name: interbank_clearing_positions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.interbank_clearing_positions ALTER COLUMN id SET DEFAULT nextval('public.interbank_clearing_positions_id_seq'::regclass);


--
-- Name: journals journal_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.journals ALTER COLUMN journal_id SET DEFAULT nextval('public.journals_journal_id_seq'::regclass);


--
-- Name: kyc_profiles id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kyc_profiles ALTER COLUMN id SET DEFAULT nextval('public.kyc_profiles_id_seq'::regclass);


--
-- Name: ledger_accounts id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ledger_accounts ALTER COLUMN id SET DEFAULT nextval('public.ledger_accounts_id_seq'::regclass);


--
-- Name: network_authorizations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_authorizations ALTER COLUMN id SET DEFAULT nextval('public.network_authorizations_id_seq'::regclass);


--
-- Name: participants participant_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.participants ALTER COLUMN participant_id SET DEFAULT nextval('public.participants_participant_id_seq'::regclass);


--
-- Name: processed_deposits id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.processed_deposits ALTER COLUMN id SET DEFAULT nextval('public.processed_deposits_id_seq'::regclass);


--
-- Name: sessions session_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions ALTER COLUMN session_id SET DEFAULT nextval('public.sessions_session_id_seq'::regclass);


--
-- Name: settlement_instructions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_instructions ALTER COLUMN id SET DEFAULT nextval('public.settlement_instructions_id_seq'::regclass);


--
-- Name: swap_audit id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_audit ALTER COLUMN id SET DEFAULT nextval('public.swap_audit_id_seq'::regclass);


--
-- Name: swap_internal_accounts id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_internal_accounts ALTER COLUMN id SET DEFAULT nextval('public.swap_internal_accounts_id_seq'::regclass);


--
-- Name: swap_ledger ledger_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledger ALTER COLUMN ledger_id SET DEFAULT nextval('public.swap_ledger_ledger_id_seq'::regclass);


--
-- Name: swap_ledgers ledger_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledgers ALTER COLUMN ledger_id SET DEFAULT nextval('public.swap_ledgers_ledger_id_seq'::regclass);


--
-- Name: swap_linked_banks id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_linked_banks ALTER COLUMN id SET DEFAULT nextval('public.swap_linked_banks_id_seq'::regclass);


--
-- Name: swap_middleman id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_middleman ALTER COLUMN id SET DEFAULT nextval('public.swap_middleman_id_seq'::regclass);


--
-- Name: swap_requests swap_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_requests ALTER COLUMN swap_id SET DEFAULT nextval('public.swap_requests_swap_id_seq'::regclass);


--
-- Name: swap_settings id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_settings ALTER COLUMN id SET DEFAULT nextval('public.swap_settings_id_seq'::regclass);


--
-- Name: swap_transactions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_transactions ALTER COLUMN id SET DEFAULT nextval('public.swap_transactions_id_seq'::regclass);


--
-- Name: transaction_reversals id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transaction_reversals ALTER COLUMN id SET DEFAULT nextval('public.transaction_reversals_id_seq'::regclass);


--
-- Name: transactions transaction_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transactions ALTER COLUMN transaction_id SET DEFAULT nextval('public.transactions_transaction_id_seq'::regclass);


--
-- Name: users user_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN user_id SET DEFAULT nextval('public.users_user_id_seq'::regclass);


--
-- Name: voucher_cashout_details id; Type: DEFAULT; Schema: public; Owner: swap_admin
--

ALTER TABLE ONLY public.voucher_cashout_details ALTER COLUMN id SET DEFAULT nextval('public.voucher_cashout_details_id_seq'::regclass);


--
-- Name: wallet_locks id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallet_locks ALTER COLUMN id SET DEFAULT nextval('public.wallet_locks_id_seq'::regclass);


--
-- Name: zurubank_middleman id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zurubank_middleman ALTER COLUMN id SET DEFAULT nextval('public.zurubank_middleman_id_seq'::regclass);


--
-- Data for Name: account_freezes; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.account_freezes (freeze_id, account_id, reason, frozen_by, start_time, end_time) FROM stdin;
\.


--
-- Data for Name: accounting_closures; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.accounting_closures (closure_date, closed_by, closed_at, closure_type, remarks) FROM stdin;
\.


--
-- Data for Name: accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.accounts (account_id, user_id, account_number, account_type, balance, currency, status, created_at) FROM stdin;
2	1	CUR00000001	current	1000.0000	USD	active	2025-12-29 13:44:00.203513
1	1	SAV00000001	savings	889.7800	USD	active	2025-12-29 13:44:00.203513
5	4	SMS_PROVIDER_001	sms_provider_settlement	10000.5100	BWP	active	2025-11-10 21:35:25
4	3	MIDDLEMAN_REV_001	middleman_revenue	50015.2800	BWP	active	2025-11-10 21:35:25
6	3	MIDDLEMAN_ESCROW_001	middleman_escrow	975778.7000	BWP	active	2025-11-10 21:35:25
7	5	VOUCHER-SUSPENSE-001	voucher_suspense	9999820.0000	BWP	active	2026-02-12 12:23:31.375513
3	2	ZURU_SETTLE_001	partner_bank_settlement	1000226.2300	BWP	active	2025-11-10 21:35:25
10	1	10000001	checking	2100.0000	BWP	active	2026-02-26 23:01:13.973497
8	1	1234567890	SAVINGS	7600.0000	BWP	active	2026-02-24 22:41:08.177685
\.


--
-- Data for Name: api_keys; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.api_keys (id, client_name, api_key, active, created_at, status) FROM stdin;
1	VouchMorph Sandbox	918446212c28e04075df3f4bfa4ae3971091379af233ab69c7ec7e5c2d5b17d9 	t	2026-02-24 15:09:47.718973	ACTIVE
2	Test Client	test_key_123	t	2026-02-24 22:31:39.900662	ACTIVE
\.


--
-- Data for Name: api_message_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.api_message_logs (log_id, message_id, message_type, direction, participant_id, participant_name, endpoint, request_payload, response_payload, http_status_code, curl_error, success, duration_ms, retry_count, created_at, processed_at) FROM stdin;
\.


--
-- Data for Name: atm_dispenses; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.atm_dispenses (id, atm_id, trace_number, amount, currency, status, created_at) FROM stdin;
1	ATM001	328862426107	500.0000	BWP	DISPENSED	2026-02-27 22:49:21.484115
\.


--
-- Data for Name: atm_requests; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.atm_requests (atm_request_id, atm_id, trace_number, request_time, status, response, swap_number, swap_pin, user_phone) FROM stdin;
\.


--
-- Data for Name: atm_terminals; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.atm_terminals (atm_id, location, status, total_cash, reserved_cash, last_sync) FROM stdin;
\.


--
-- Data for Name: audit_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.audit_logs (id, entity, entity_id, action, category, severity, old_value, new_value, performed_at, performed_by, ip_address, user_agent, geo_location) FROM stdin;
1	accounts	8	INTERBANK_DEPOSIT	financial	info	\N	\N	2026-02-26 20:02:09.630331	1	\N	\N	\N
2	instant_money_vouchers	17	SETTLE	financial	info	\N	\N	2026-02-27 20:09:09.773113	2	\N	\N	\N
3	instant_money_vouchers	23	DEBIT	financial	info	\N	\N	2026-03-02 08:16:32.695009	1	\N	\N	\N
4	instant_money_vouchers	26	DEBIT	financial	info	\N	\N	2026-03-02 09:02:54.598388	1	\N	\N	\N
5	instant_money_vouchers	25	DEBIT	financial	info	\N	\N	2026-03-02 11:26:28.180445	1	\N	\N	\N
\.


--
-- Data for Name: cashouts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.cashouts (cashout_id, trace_number, cashout_reference, destination_bank_id, atm_id, user_id, amount, currency, status, created_at, dispensed_at, swap_number, swap_pin, user_phone, agent_id, source_bank_id) FROM stdin;
1	586361180565	CASHOUT-77350983	2	ATM001	1	1500.0000	BWP	PENDING_SETTLEMENT	2026-02-26 21:56:07.24955	2026-02-26 21:56:07.24955	SET1772135772758	\N	\N	\N	\N
3	328862426107	CASHOUT-1772224572-426107	2	ATM001	1	500.0000	BWP	COMPLETED	2026-02-27 22:36:12.232976	2026-02-27 22:49:21.484115	\N	\N	\N	\N	1
4	654258652742	CASHOUT-1772279049-652742	2	\N	1	100.0000	BWP	READY	2026-02-28 13:44:09.882441	\N	\N	\N	\N	\N	1
5	204327767621	CASHOUT-1772279054-767621	2	\N	1	100.0000	BWP	READY	2026-02-28 13:44:14.751456	\N	\N	\N	\N	\N	1
6	927149583563	CASHOUT-1772279158-583563	2	\N	1	100.0000	BWP	READY	2026-02-28 13:45:58.994185	\N	\N	\N	\N	\N	1
7	468530453308	CASHOUT-1772279572-453308	2	\N	1	100.0000	BWP	READY	2026-02-28 13:52:52.089535	\N	\N	\N	\N	\N	1
8	187943147702	CASHOUT-1772279797-147702	2	\N	1	100.0000	BWP	READY	2026-02-28 13:56:37.799363	\N	\N	\N	\N	\N	1
9	319886326605	CASHOUT-1772280365-326605	2	\N	1	100.0000	BWP	READY	2026-02-28 14:06:05.754753	\N	\N	\N	\N	\N	1
10	243082925322	CASHOUT-1772286391-925322	2	\N	1	1.0000	BWP	READY	2026-02-28 15:46:30.99354	\N	\N	\N	\N	\N	1
11	511264154750	CASHOUT-1772286686-154750	2	\N	1	1.0000	BWP	READY	2026-02-28 15:51:26.135065	\N	\N	\N	\N	\N	1
12	703337860851	CASHOUT-1772286767-860851	2	\N	1	100.0000	BWP	READY	2026-02-28 15:52:47.582816	\N	\N	\N	\N	\N	1
13	137784084242	CASHOUT-1772435062-084242	2	\N	1	100.0000	BWP	READY	2026-03-02 09:04:22.909753	\N	\N	\N	\N	\N	1
\.


--
-- Data for Name: central_bank_link; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.central_bank_link (id, bank_id, link_status, last_sync) FROM stdin;
\.


--
-- Data for Name: chart_of_accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.chart_of_accounts (coa_code, coa_name, coa_type, parent_coa_code, is_customer_account, is_trust_account, created_at) FROM stdin;
1000	Cash & Central Bank Reserves	asset	\N	f	t	2026-02-10 21:54:31.214128
2000	Customer Deposit Liabilities	liability	\N	f	f	2026-02-10 21:54:31.214128
2100	Voucher Suspense Liability	liability	\N	f	f	2026-02-10 21:54:31.214128
4000	Transaction Fee Income	income	\N	f	f	2026-02-10 21:54:31.214128
\.


--
-- Data for Name: data_retention_policies; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.data_retention_policies (entity_name, retention_years, legal_basis, last_reviewed) FROM stdin;
\.


--
-- Data for Name: disaster_recovery_tests; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.disaster_recovery_tests (test_id, test_date, test_type, systems_tested, result, issues_found, resolved, signed_off_by) FROM stdin;
\.


--
-- Data for Name: external_banks; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.external_banks (id, user_id, bank_name, account_number) FROM stdin;
\.


--
-- Data for Name: hold_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hold_transactions (hold_id, hold_reference, swap_reference, participant_id, participant_name, asset_type, amount, currency, status, hold_expiry, source_details, destination_institution, destination_participant_id, metadata, placed_at, released_at, debited_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: idempotency_keys; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.idempotency_keys (key_value, response, created_at) FROM stdin;
\.


--
-- Data for Name: incoming_pre_advice; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.incoming_pre_advice (id, trace_number, issuer_bank_id, destination_bank_id, user_id, amount, currency, status, cashout_reference, created_at, completed_at, notified_to_vouchmorph, cashout_created_at) FROM stdin;
2	586361180565	1	2	1	1500.0000	BWP	AUTHORIZED	CASHOUT-77350983	2026-02-26 21:56:12.537585	\N	f	\N
\.


--
-- Data for Name: instant_money_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.instant_money_transactions (transaction_id, wallet_id, type, amount, reference, related_account_id, status, created_at) FROM stdin;
\.


--
-- Data for Name: instant_money_transfers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.instant_money_transfers (transfer_id, from_wallet_id, to_wallet_id, amount, reference, status, created_at) FROM stdin;
\.


--
-- Data for Name: instant_money_vouchers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.instant_money_vouchers (voucher_id, amount, currency, created_by, recipient_phone, created_at, voucher_number, voucher_pin, redeemed_by, voucher_created_at, voucher_expires_at, sat_purchased, sat_fee_paid_by, sat_expires_at, redeemed_at, swap_made_at, holding_account, status, origin, external_reference, source_institution, source_hold_reference) FROM stdin;
25	1500.00	BWP	1	+26770000000	2026-02-26 21:50:22.512047	458063031195	377975	\N	2026-02-26 21:50:22.512047	\N	f	sender	2026-02-27 20:50:22	2026-03-02 11:26:28.180445	\N	VOUCHER-SUSPENSE	redeemed	swap	ee1c9f897a174639e584727e51268555	\N	ee1c9f897a174639e584727e51268555
24	1500.00	BWP	1	+26770000000	2026-02-26 17:54:17.825023	028271632899	046663	\N	2026-02-26 17:54:17.825023	2026-03-27 16:54:17	f	sender	2026-03-27 16:54:17	2026-02-26 19:36:23.136197	\N	VOUCHER-SUSPENSE	redeemed	swap	\N	\N	\N
21	500.00	BWP	1	\N	2026-02-24 22:34:40.793451	VCH1771965280434	$2y$10$fLoPN4.J6NQ0sA86RfyDCex3RwNDl0vJK7UAjnfXmTD3p5Cq13owG	\N	2026-02-24 22:34:40.793451	2026-02-25 21:34:40	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
23	1500.00	BWP	1	+26770000000	2026-02-26 17:49:20.977818	326059135833	516060	\N	2026-02-26 17:49:20.977818	2026-03-27 16:54:17	f	sender	2026-03-27 16:49:20	2026-03-02 08:16:32.695009	\N	VOUCHER-SUSPENSE	active	swap	3145a5cc507709e58a4767f6c7499984	\N	3145a5cc507709e58a4767f6c7499984
22	500.00	BWP	1	\N	2026-02-24 22:45:31.347238	VCH1771965931488	$2y$10$1iLev92/dP6zulxQSKzC1eHYdDykSZ7bC/srPlTcaqxMNl3nlixTS	\N	2026-02-24 22:45:31.347238	2026-02-25 21:45:31	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
7	100.00	BWP	1	+26770000000	2025-12-29 16:56:14.586213	653700292	078542	2	2025-12-29 16:56:14.586213	2026-01-05 16:56:14.586213	f	sender	2025-12-30 16:56:14.586213	2026-01-03 01:51:08.643235	2025-12-29 16:56:14.586213	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
26	1500.00	BWP	1	+26770000000	2026-02-26 21:56:07.24955	586361180565	868597	\N	2026-02-26 21:56:07.24955	\N	f	sender	2026-02-27 20:56:07	2026-03-02 09:02:54.598388	\N	VOUCHER-SUSPENSE	active	swap	897ba2207e18cc592d82fffa28be41ff	\N	897ba2207e18cc592d82fffa28be41ff
17	80.00	BWP	2	+26770000000	2026-01-25 01:46:10.6781	800259143	237896	5	2026-01-25 01:46:10.6781	\N	f	sender	\N	2026-02-27 20:09:09.773113	\N	VOUCHER-SUSPENSE	redeemed	zurubank	\N	\N	\N
28	500.00	BWP	1	+26771123456	2026-02-27 22:36:12.232976	328862426107	430668	9	2026-02-27 22:36:12.232976	2026-02-28 21:36:12	f	sender	\N	2026-02-27 22:49:21.484115	\N	VOUCHER-SUSPENSE	redeemed	zurubank	\N	\N	\N
29	100.00	BWP	1	+26770000000	2026-02-28 13:44:09.882441	654258652742	177716	\N	2026-02-28 13:44:09.882441	2026-03-01 12:44:09	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	8ff2183d080a61f22fe50092b828e0a3	SACCUSSALIS	8ff2183d080a61f22fe50092b828e0a3
18	80.00	BWP	2	+26770000000	2026-01-25 02:42:06.152388	466908717	688906	\N	2026-01-25 02:42:06.152388	\N	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
9	100.00	BWP	2	+26770000000	2026-01-03 02:11:54.399018	336080211	556190	2	2026-01-03 02:11:54.399018	\N	f	sender	\N	2026-02-05 16:07:46.151961	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
10	100.00	BWP	2	+26770000000	2026-01-03 02:29:26.587719	332261062	906683	2	2026-01-03 02:29:26.587719	\N	f	sender	\N	2026-02-07 18:37:47.767241	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
11	100.00	BWP	2	+26770000000	2026-01-03 09:29:46.805201	731011909	421097	2	2026-01-03 09:29:46.805201	\N	f	sender	\N	2026-02-07 23:30:49.589367	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
12	100.00	BWP	2	+26770000000	2026-01-03 09:38:20.980066	699736955	757892	2	2026-01-03 09:38:20.980066	\N	f	sender	\N	2026-02-09 09:18:04.940531	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
13	100.00	BWP	2	+26770000000	2026-01-03 09:43:14.331124	986081459	727177	2	2026-01-03 09:43:14.331124	\N	f	sender	\N	2026-02-09 09:38:41.887355	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
14	100.00	BWP	2	+26770000000	2026-01-03 09:58:54.317945	311700170	724365	2	2026-01-03 09:58:54.317945	\N	f	sender	\N	2026-02-09 12:32:45.285333	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
15	80.00	BWP	2	+26770000000	2026-01-03 10:12:15.034053	493387424	361485	2	2026-01-03 10:12:15.034053	\N	f	sender	\N	2026-02-09 15:52:34.369759	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
16	100.00	BWP	2	+26770000000	2026-01-03 10:12:58.137025	261582648	467843	5	2026-01-03 10:12:58.137025	\N	f	sender	\N	2026-02-12 12:48:27.12996	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
20	60.00	BWP	2	+26770000000	2026-02-09 09:27:04.261949	701437495	128607	\N	2026-02-09 09:27:04.261949	\N	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
8	100.00	BWP	2	+26770000000	2025-12-30 20:23:33.334949	175043280	522401	2	2025-12-30 20:23:33.334949	\N	f	sender	\N	2026-02-04 23:53:45.07024	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
30	100.00	BWP	1	+26770000000	2026-02-28 13:44:14.751456	204327767621	464810	\N	2026-02-28 13:44:14.751456	2026-03-01 12:44:14	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	TEST_1772279054	SACCUSSALIS	\N
19	80.00	BWP	2	+26770000000	2026-01-25 10:50:33.672107	866267976	567160	\N	2026-01-25 10:50:33.672107	\N	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	\N	\N	\N
31	100.00	BWP	1	+26770000000	2026-02-28 13:45:58.994185	927149583563	400673	\N	2026-02-28 13:45:58.994185	2026-03-01 12:45:58	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	8a46a9434b7e132f2771f684311cede8	SACCUSSALIS	8a46a9434b7e132f2771f684311cede8
32	100.00	BWP	1	+26770000000	2026-02-28 13:52:52.089535	468530453308	292196	\N	2026-02-28 13:52:52.089535	2026-03-01 12:52:52	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	d587b9a91ea834ccf3068569f30e6a0e	SACCUSSALIS	d587b9a91ea834ccf3068569f30e6a0e
33	100.00	BWP	1	+26770000000	2026-02-28 13:56:37.799363	187943147702	302238	\N	2026-02-28 13:56:37.799363	2026-03-01 12:56:37	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	f0191648e17fd091eb2096d8dd4bd132	SACCUSSALIS	f0191648e17fd091eb2096d8dd4bd132
34	100.00	BWP	1	+26770000000	2026-02-28 14:06:05.754753	319886326605	602273	\N	2026-02-28 14:06:05.754753	2026-03-01 13:06:05	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	7847ef137e2e8fe4560717e4a416e21f	SACCUSSALIS	7847ef137e2e8fe4560717e4a416e21f
35	1.00	BWP	1	+26770000000	2026-02-28 15:46:30.99354	243082925322	167507	\N	2026-02-28 15:46:30.99354	2026-03-01 14:46:31	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	VCH-69a2f1b6f29c1	SACCUSSALIS	\N
36	1.00	BWP	1	+26770000000	2026-02-28 15:51:26.135065	511264154750	570862	\N	2026-02-28 15:51:26.135065	2026-03-01 14:51:26	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	VCH-69a2f2de210c9	SACCUSSALIS	\N
37	100.00	BWP	1	+26770000000	2026-02-28 15:52:47.582816	703337860851	853807	\N	2026-02-28 15:52:47.582816	2026-03-01 14:52:47	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	805a45ee0bf22b8cd566c778ce106c81	SACCUSSALIS	805a45ee0bf22b8cd566c778ce106c81
38	100.00	BWP	1	+26770000000	2026-03-02 09:04:22.909753	137784084242	091829	\N	2026-03-02 09:04:22.909753	2026-03-03 08:04:22	f	sender	\N	\N	\N	VOUCHER-SUSPENSE	active	zurubank	8c4dc51e09119dd0ee509a31c4eb6070	SACCUSSALIS	8c4dc51e09119dd0ee509a31c4eb6070
\.


--
-- Data for Name: instant_money_wallets; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.instant_money_wallets (wallet_id, user_id, balance, currency, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: interbank_clearing_positions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.interbank_clearing_positions (id, debtor_bank, creditor_bank, amount, currency, trace_number, settlement_status, business_date) FROM stdin;
1	VOUCHMORPH	ZURUBANK	1500.0000	BWP	IBD1772128929314	PENDING	2026-02-26
\.


--
-- Data for Name: journals; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.journals (journal_id, reference, description, created_at) FROM stdin;
6	IBD1772128929314	Incoming Interbank Deposit	2026-02-26 20:02:09.630331
8	SET1772215749	Settlement of voucher 800259143 used at SACCUSSALIS	2026-02-27 20:09:09.773113
9	3145a5cc507709e58a4767f6c7499984	Settlement of voucher 326059135833 used at SACCUSSALIS	2026-03-02 08:16:32.695009
10	897ba2207e18cc592d82fffa28be41ff	Settlement of voucher 586361180565 used at SACCUSSALIS	2026-03-02 09:02:54.598388
11	ee1c9f897a174639e584727e51268555	Settlement of voucher 458063031195 used at SACCUSSALIS	2026-03-02 11:26:28.180445
\.


--
-- Data for Name: kyc_profiles; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.kyc_profiles (id, user_id, kyc_level, risk_rating, source_of_funds, pep, sanctions_checked, last_reviewed_at, created_at) FROM stdin;
\.


--
-- Data for Name: ledger_accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.ledger_accounts (id, account_name, account_number, account_type, balance, currency, created_at) FROM stdin;
1	ZURU_SETTLE_001	ZURU_SETTLE_001	partner_bank_settlement	1000050.0000	BWP	2025-11-10 21:35:25
2	MIDDLEMAN_REV_001	MIDDLEMAN_REV_001	middleman_revenue	50007.2000	BWP	2025-11-10 21:35:25
3	SMS_PROVIDER_001	SMS_PROVIDER_001	sms_provider_settlement	10000.5000	BWP	2025-11-10 21:35:25
4	MIDDLEMAN_ESCROW_001	MIDDLEMAN_ESCROW_001	middleman_escrow	975999.0000	BWP	2025-11-10 21:35:25
5	Voucher Suspense Account	VOUCHER-SUSPENSE	liability	0.0000	BWP	2026-02-10 21:57:23.934315
\.


--
-- Data for Name: network_authorizations; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.network_authorizations (id, trace_number, role, counterparty_bank, amount, currency, token_hash, auth_code, status, expiry_time, used_at, created_at) FROM stdin;
\.


--
-- Data for Name: participants; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.participants (participant_id, name, type, category, provider_code, auth_type, base_url, system_user_id, legal_entity_identifier, license_number, settlement_account, settlement_type, status, capabilities, resource_endpoints, phone_format, security_config, message_profile, routing_info, metadata, created_at, updated_at) FROM stdin;
1	ZURUBANK	FINANCIAL_INSTITUTION	BANK	ZURUBWXX	MTLS_OAUTH2	http://localhost/zurubank/Backend	1001	BW-ZURUBANK-LEI-001	CB-BW-017	\N	\N	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "VOUCHER", "ATM"], "supports_reversal": true, "supports_idempotency": true, "supports_realtime_processing": true}	{"place_hold": "/api/v1/hold.php", "debit_funds": "/api/v1/settlement/notify_debit.php", "release_hold": "/api/v1/hold.php", "verify_asset": "/api/v1/verify_asset.php", "verify_token": "/api/v1/atm/atm_cashout.php", "generate_token": "/api/v1/atm/generate_code.php", "confirm_cashout": "/api/v1/atm_cashout.php", "process_deposit": "/api/v1/deposit/direct.php", "reverse_transaction": "/api/v1/hold.php"}	{"prefix": "+", "country_code": "267", "expected_length": 11, "strip_leading_zeros": true, "remove_country_code_for_local": false}	{"oauth2": {"scope": "payments settlement", "client_id_env": "ZURUBANK_CLIENT_ID", "token_endpoint": "/oauth2/token", "client_secret_env": "ZURUBANK_CLIENT_SECRET"}, "api_key": {"value_env": "API_KEY_ZURUBANK", "header_name": "X-API-KEY"}, "request_signing": {"algorithm": "RSA-SHA256", "private_key_env": "ZURUBANK_SIGNING_KEY"}}	{"content_standard": "ISO20022_JSON", "signature_header": "X-Signature", "timestamp_header": "X-Timestamp", "correlation_id_header": "X-Correlation-ID", "idempotency_key_header": "X-Idempotency-Key"}	\N	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
2	SACCUSSALIS	FINANCIAL_INSTITUTION	BANK	SACCUSBWXX	MTLS_OAUTH2	http://localhost/SaccusSalisbank/backend	1001	BW-SACCUSSALIS-LEI-001	CB-BW-027	\N	\N	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "E-WALLET", "ATM"], "supports_reversal": true, "supports_idempotency": true, "supports_realtime_processing": true}	{"place_hold": "/api/v1/hold.php", "debit_funds": "/api/v1/notify_debit.php", "release_hold": "/api/v1/hold.php", "verify_asset": "/api/v1/verify_asset.php", "verify_token": "/api/v1/atm/verify-token/index.php", "generate_token": "/api/v1/atm/generate_code.php", "confirm_cashout": "/api/v1/confirm_cashout.php", "process_deposit": "/api/v1/transaction/credit_funds.php", "reverse_transaction": "/api/v1/hold.php"}	{"prefix": "+", "country_code": "267", "expected_length": 11, "strip_leading_zeros": true, "remove_country_code_for_local": false}	{"oauth2": {"scope": "payments settlement ewallet atm", "client_id_env": "SACCUSSALIS_CLIENT_ID", "token_endpoint": "/oauth2/token", "client_secret_env": "SACCUSSALIS_CLIENT_SECRET"}, "api_key": {"value_env": "API_KEY_SACCUSSALIS", "header_name": "X-API-KEY"}, "request_signing": {"algorithm": "RSA-SHA256", "private_key_env": "SACCUSSALIS_SIGNING_KEY"}}	{"content_standard": "ISO20022_JSON", "signature_header": "X-Signature", "timestamp_header": "X-Timestamp", "correlation_id_header": "X-Correlation-ID", "idempotency_key_header": "X-Idempotency-Key"}	\N	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
3	TEST_BANK_A	FINANCIAL_INSTITUTION	BANK	TEST_BIC_A	MTLS_OAUTH2	https://sandbox-bank.local	1	TEST_LEI_001	CB-BW-001	TEST_ACC_001	RTGS_TRANSIT	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "VOUCHER"], "supports_realtime_settlement": true}	{"reversal": "/sandbox/payments/{id}/reversal", "funds_confirmation": "/sandbox/accounts/{id}/balance", "payment_initiation": "/sandbox/payments", "beneficiary_validation": "/sandbox/identity/verify"}	\N	\N	\N	{"settlement_type": "RTGS_TRANSIT", "settlement_account": "TEST_ACC_001"}	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
4	TEST_BANK_B	FINANCIAL_INSTITUTION	BANK	TEST_BIC_B	MTLS_OAUTH2	https://sandbox-bank-b.local	2	TEST_LEI_002	CB-BW-002	TEST_ACC_002	RTGS_TRANSIT	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "VOUCHER"], "supports_realtime_settlement": true}	{"status_query": "/api/v1/transaction/status.php", "credit_transfer": "/api/v1/deposit/direct.php", "identity_lookup": "/api/v1/verify_account.php", "voucher_request": "/api/v1/atm/generate_code.php", "reverse_transaction": "/api/v1/transaction/reverse.php", "settlement_instruction": "/api/v1/settlement/notify_debit.php"}	\N	\N	\N	{"settlement_type": "RTGS_TRANSIT", "settlement_account": "TEST_ACC_002"}	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
5	TEST_MNO_A	MOBILE_MONEY_OPERATOR	MNO	TEST_MNC_A	OAUTH2_JWT	https://sandbox-mno.local	\N	\N	\N	\N	\N	ACTIVE	{"supports_sca": true, "wallet_types": ["WALLET"], "supports_realtime_disbursement": true}	{"kyc_check": "/sandbox/subscribers/{msisdn}/validate", "collection": "/sandbox/request-to-pay", "disbursement": "/sandbox/disbursements"}	\N	\N	\N	\N	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
6	TEST_MNO_B	MOBILE_MONEY_OPERATOR	MNO	TEST_MNC_B	OAUTH2_JWT	https://sandbox-mno-b.local	\N	\N	\N	\N	\N	ACTIVE	{"supports_sca": true, "wallet_types": ["WALLET"], "supports_realtime_disbursement": true}	{"kyc_check": "/sandbox/subscribers/{msisdn}/validate", "collection": "/sandbox/request-to-pay", "disbursement": "/sandbox/disbursements"}	\N	\N	\N	\N	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
7	TEST_DISTRIBUTOR_A	CARD_DISTRIBUTOR	EMI_CARD	TEST_EMV_A	MTLS_OAUTH2	https://sandbox-distributor.local	\N	\N	\N	\N	\N	ACTIVE	{"wallet_types": ["CARD"], "supports_top_up": true, "supports_card_issue": true}	{"top_up": "/sandbox/cards/{id}/load", "card_issue": "/sandbox/cards/issue"}	\N	\N	\N	\N	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
8	TEST_DISTRIBUTOR_B	CARD_DISTRIBUTOR	EMI_CARD	TEST_EMV_B	MTLS_OAUTH2	https://sandbox-distributor-b.local	\N	\N	\N	\N	\N	ACTIVE	{"wallet_types": ["CARD"], "supports_top_up": true, "supports_card_issue": true}	{"top_up": "/sandbox/cards/{id}/load", "card_issue": "/sandbox/cards/issue"}	\N	\N	\N	\N	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
9	ALPHA	FINANCIAL_INSTITUTION	BANK	ALPHA_BIC	MTLS_OAUTH2	https://sandbox-alpha.local	9999	ALPHA_LEI_001	\N	ALPHA_ACC_001	RTGS_TRANSIT	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "E-WALLET"], "supports_realtime_settlement": true}	\N	\N	\N	\N	{"settlement_type": "RTGS_TRANSIT", "settlement_account": "ALPHA_ACC_001"}	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
10	BRAVO	FINANCIAL_INSTITUTION	BANK	BRAVO_BIC	MTLS_OAUTH2	https://sandbox-bravo.local	9002	BRAVO_LEI_002	\N	BRAVO_ACC_001	RTGS_TRANSIT	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "VOUCHER"], "supports_realtime_settlement": true}	\N	\N	\N	\N	{"settlement_type": "RTGS_TRANSIT", "settlement_account": "BRAVO_ACC_001"}	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
11	CHARLIE	FINANCIAL_INSTITUTION	BANK	CHARLIE_BIC	MTLS_OAUTH2	https://sandbox-charlie.local	9003	CHARLIE_LEI_003	\N	CHARLIE_ACC_001	RTGS_TRANSIT	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "E-WALLET"], "supports_realtime_settlement": true}	\N	\N	\N	\N	{"settlement_type": "RTGS_TRANSIT", "settlement_account": "CHARLIE_ACC_001"}	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
12	CARD	CARD_DISTRIBUTOR	EMI_CARD	CARD_EMV	MTLS_OAUTH2	https://sandbox-card.local	9004	CARD_LEI_004	\N	\N	\N	ACTIVE	{"wallet_types": ["CARD"], "supports_top_up": true, "supports_card_issue": true}	{"top_up": "/sandbox/cards/{id}/load", "card_issue": "/sandbox/cards/issue"}	\N	\N	\N	\N	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
13	BANK A	FINANCIAL_INSTITUTION	BANK	BANK_BIC	MTLS_OAUTH2	https://sandbox-bank2.local	9005	BANK_LEI_005	\N	BANK_ACC_001	RTGS_TRANSIT	ACTIVE	{"supports_sca": true, "wallet_types": ["ACCOUNT", "E-WALLET"], "supports_realtime_settlement": true}	{"funds_confirmation": "/sandbox/accounts/{id}/balance", "payment_initiation": "/sandbox/payments"}	\N	\N	\N	{"settlement_type": "RTGS_TRANSIT", "settlement_account": "BANK_ACC_001"}	\N	2026-03-02 05:15:07.804051+02	2026-03-02 05:47:23.367099+02
\.


--
-- Data for Name: processed_deposits; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.processed_deposits (id, deposit_ref, account_number, amount, idempotency_key, processed_at) FROM stdin;
1	IBD1772128929314	1234567890	1500.00	VM-DEP-001	2026-02-26 20:02:09.630331
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sessions (session_id, user_id, token, expires_at, created_at) FROM stdin;
2	1	7178e3d4a66e21b58da0f5e11620749705eef5843f915762a9284b6533268957	2025-12-30 00:56:19	2025-12-29 13:56:19.541848
3	1	1b7766ddecf741496788c8af34d6be99e97a892353779c1926f58115dc008d36	2025-12-30 03:17:34	2025-12-29 16:17:34.528931
\.


--
-- Data for Name: settlement_instructions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.settlement_instructions (id, reference, debit_ref, account_number, amount, type, status, idempotency_key, created_at, processed_at) FROM stdin;
1	STL-17719659314865	DEB1771965931	1234567890	250.00	DEBIT	PROCESSED	IDEMP1771965932	2026-02-24 22:45:31.419364	2026-02-24 22:45:31.419364
12	SET177214029749373	466908717	10000001	80.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:466908717	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
13	SET177214029750274	336080211	10000001	100.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:336080211	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
14	SET177214029750754	332261062	10000001	100.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:332261062	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
15	SET177214029751035	731011909	10000001	100.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:731011909	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
16	SET177214029751465	699736955	10000001	100.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:699736955	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
17	SET177214029752283	986081459	10000001	100.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:986081459	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
18	SET177214029752622	311700170	10000001	100.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:311700170	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
19	SET177214029753014	493387424	10000001	80.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:493387424	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
20	SET177214029753347	261582648	10000001	100.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:261582648	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
21	SET177214029753888	701437495	10000001	60.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:701437495	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
22	SET177214029753971	800259143	10000001	80.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:800259143	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
23	SET177214029754027	VCH1771965280434	10000001	500.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:VCH1771965280434	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
24	SET177214029754465	VCH1771965931488	10000001	500.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:VCH1771965931488	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
25	SET177214029754724	653700292	10000001	100.00	DEBIT	PROCESSED	TESTSETTLE_1772139981965391456:653700292	2026-02-26 23:11:37.474072	2026-02-26 23:11:37.474072
\.


--
-- Data for Name: swap_audit; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_audit (id, action_type, actor, reference, details, created_at) FROM stdin;
\.


--
-- Data for Name: swap_internal_accounts; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_internal_accounts (id, account_code, purpose, balance, currency, status, created_at) FROM stdin;
\.


--
-- Data for Name: swap_ledger; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_ledger (ledger_id, reference_id, debit_account, credit_account, amount, currency, description, created_at, ref_voucher_id, is_deleted, updated_at, journal_id) FROM stdin;
2	653700292	1	6	10.22	BWP	CREATION-FEE-653700292	2025-12-29 16:56:14.586213	\N	f	2026-02-10 21:54:31.214128	\N
3	653700292	6	3	0.13	BWP	USED-BANK-653700292	2025-12-29 16:56:14.586213	\N	f	2026-02-10 21:54:31.214128	\N
4	653700292	6	4	0.08	BWP	USED-MID-653700292	2025-12-29 16:56:14.586213	\N	f	2026-02-10 21:54:31.214128	\N
5	653700292	6	5	0.01	BWP	USED-SMS-653700292	2025-12-29 16:56:14.586213	\N	f	2026-02-10 21:54:31.214128	\N
11	IBD1772128929314	1000	2000	1500.00	BWP	Interbank Deposit: From VOUCHMORPH to Account 1234567890	2026-02-26 20:02:09.630331	\N	f	2026-02-26 20:02:09.630331	6
13	SET1772215749	VOUCHER-SUSPENSE	INTERBANK:SACCUSSALIS	80.00	BWP	Voucher 800259143 cashed at SACCUSSALIS	2026-02-27 20:09:09.773113	\N	f	2026-02-27 20:09:09.773113	8
14	328862426107	VOUCHER-SUSPENSE	ATM:ATM001	500.00	BWP	ATM cashout settlement for voucher 328862426107	2026-02-27 22:49:21.484115	\N	f	2026-02-27 22:49:21.484115	\N
15	3145a5cc507709e58a4767f6c7499984	VOUCHER-SUSPENSE	INTERBANK:SACCUSSALIS	1500.00	BWP	Voucher 326059135833 cashed at SACCUSSALIS	2026-03-02 08:16:32.695009	\N	f	2026-03-02 08:16:32.695009	9
16	897ba2207e18cc592d82fffa28be41ff	VOUCHER-SUSPENSE	INTERBANK:SACCUSSALIS	1500.00	BWP	Voucher 586361180565 cashed at SACCUSSALIS	2026-03-02 09:02:54.598388	\N	f	2026-03-02 09:02:54.598388	10
17	ee1c9f897a174639e584727e51268555	VOUCHER-SUSPENSE	INTERBANK:SACCUSSALIS	1500.00	BWP	Voucher 458063031195 cashed at SACCUSSALIS	2026-03-02 11:26:28.180445	\N	f	2026-03-02 11:26:28.180445	11
\.


--
-- Data for Name: swap_ledgers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_ledgers (ledger_id, swap_reference, from_participant, to_participant, from_type, to_type, from_account, to_account, original_amount, final_amount, currency_code, swap_fee, creation_fee, admin_fee, sms_fee, token, status, reverse_logic, performed_by, notes, created_at, updated_at) FROM stdin;
1	IBD1772128929314	VOUCHMORPH	ZURUBANK	BANK	CUSTOMER_ACCOUNT	VOUCHMORPH	1234567890	1500.00	1500.00	BWP	0.00	0.00	0.00	0.00	\N	completed	f	1	Interbank Swap: Crediting customer account via VOUCHMORPH	2026-02-26 20:02:09.630331	2026-02-26 20:02:09.630331
\.


--
-- Data for Name: swap_linked_banks; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_linked_banks (id, bank_code, bank_name, api_endpoint, public_key, status, created_at) FROM stdin;
2	SACCUSSALIS	SACCUSSALIS	\N	\N	active	2026-02-27 20:09:09.773113
\.


--
-- Data for Name: swap_middleman; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_middleman (id, account_number, api_key, webhook_url, encryption_key, created_at) FROM stdin;
\.


--
-- Data for Name: swap_requests; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_requests (swap_id, swap_uuid, user_id, from_currency, to_currency, amount, converted_amount, exchange_rate, fee_amount, total_amount, status, fraud_check_status, processor_reference, metadata, completed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: swap_settings; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_settings (id, setting_key, setting_value, updated_at) FROM stdin;
\.


--
-- Data for Name: swap_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.swap_transactions (id, middleman_id, source, destination, type, amount, reference, status, created_at) FROM stdin;
\.


--
-- Data for Name: system_settings; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.system_settings (setting_key, setting_value, updated_at) FROM stdin;
\.


--
-- Data for Name: transaction_reversals; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.transaction_reversals (id, original_trace, reversal_trace, reason, reversed_at) FROM stdin;
\.


--
-- Data for Name: transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.transactions (transaction_id, user_id, account_id, from_account, to_account, type, amount, reference, description, status, created_at, swap_fee, creation_fee, admin_fee, sms_fee, rounding_adjustment, is_deleted, is_large_transaction, is_suspicious, reported_to_regulator, regulator_report_reference, trace_number) FROM stdin;
2	2	6	6	+26770000000	voucher_send	100.00	\N	\N	completed	2025-12-30 20:23:33.334949	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
3	2	6	voucher:653700292	6	voucher_redeem	100.00	\N	\N	completed	2025-12-30 20:34:09.463477	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
4	2	6	voucher:653700292	6	voucher_redeem	100.00	\N	\N	completed	2026-01-03 01:42:03.859233	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
5	2	6	6	voucher:653700292	voucher_reverse	100.00	reverse:653700292:3db6590b	Reversal of redeemed voucher 653700292	completed	2026-01-03 01:42:04.025202	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
6	2	6	voucher:653700292	6	voucher_redeem	100.00	\N	\N	completed	2026-01-03 01:46:56.510321	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
7	2	6	6	voucher:653700292	voucher_reverse	100.00	reverse:653700292:50eb91e3	Reversal of redeemed voucher 653700292	completed	2026-01-03 01:46:56.60725	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
8	2	6	voucher:653700292	6	voucher_redeem	100.00	\N	\N	completed	2026-01-03 01:51:08.643235	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
9	2	6	6	+26770000000	voucher_send	100.00	\N	\N	completed	2026-01-03 02:11:54.399018	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
10	2	6	6	+26770000000	voucher_send	100.00	\N	\N	completed	2026-01-03 02:29:26.587719	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
11	2	6	6	+26770000000	voucher_send	100.00	\N	\N	completed	2026-01-03 09:29:46.805201	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
12	2	6	6	+26770000000	voucher_send	100.00	\N	\N	completed	2026-01-03 09:38:20.980066	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
13	2	6	6	+26770000000	voucher_send	100.00	\N	\N	completed	2026-01-03 09:43:14.331124	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
14	2	6	6	+26770000000	voucher_send	100.00	\N	\N	completed	2026-01-03 09:58:54.317945	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
15	2	6	6	+26770000000	voucher_send	80.00	\N	\N	completed	2026-01-03 10:12:15.034053	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
16	2	6	6	+26770000000	voucher_send	100.00	\N	\N	completed	2026-01-03 10:12:58.137025	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
17	2	6	6	+26770000000	voucher_send	80.00	\N	\N	completed	2026-01-25 01:46:10.6781	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
18	2	6	6	+26770000000	voucher_send	80.00	\N	\N	completed	2026-01-25 02:42:06.152388	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
19	2	6	6	+26770000000	voucher_send	80.00	\N	\N	completed	2026-01-25 10:50:33.672107	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
20	2	6	voucher:175043280	6	voucher_redeem	100.00	\N	\N	completed	2026-02-04 23:51:06.374704	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
21	2	6	6	voucher:175043280	voucher_reverse	100.00	reverse:175043280:4438ca27	Reversal of redeemed voucher 175043280	completed	2026-02-04 23:51:06.498157	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
22	2	6	voucher:175043280	6	voucher_redeem	100.00	\N	\N	completed	2026-02-04 23:53:45.07024	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
23	2	6	voucher:336080211	6	voucher_redeem	100.00	\N	\N	completed	2026-02-05 16:07:46.151961	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
24	2	6	voucher:332261062	6	voucher_redeem	100.00	\N	\N	completed	2026-02-07 18:37:47.767241	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
25	2	6	voucher:731011909	6	voucher_redeem	100.00	\N	\N	completed	2026-02-07 23:30:49.589367	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
26	2	6	voucher:699736955	6	voucher_redeem	100.00	\N	\N	completed	2026-02-09 09:18:04.940531	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
27	2	6	6	+70000000	voucher_send	60.00	\N	\N	completed	2026-02-09 09:27:04.261949	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
28	2	6	voucher:986081459	6	voucher_redeem	100.00	\N	\N	completed	2026-02-09 09:38:41.887355	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
29	2	6	voucher:311700170	6	voucher_redeem	100.00	\N	\N	completed	2026-02-09 12:32:45.285333	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
30	2	6	voucher:493387424	6	voucher_redeem	80.00	\N	\N	completed	2026-02-09 15:52:34.369759	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
31	5	3	SUSPENSE_ACC	SETTLEMENT_ACC	voucher_redeem	100.00	\N	Redeemed voucher: 261582648	completed	2026-02-12 12:48:27.12996	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
32	5	3	SUSPENSE_ACC	SETTLEMENT_ACC	voucher_redeem	80.00	\N	Redeemed voucher: 800259143	completed	2026-02-12 13:01:11.307468	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
33	5	7	SETTLEMENT_ACC	SUSPENSE_ACC	voucher_reverse	80.00	\N	Reversal of redemption for voucher 800259143	completed	2026-02-12 13:01:11.52287	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
34	5	3	SUSPENSE_ACC	SETTLEMENT_ACC	voucher_redeem	80.00	\N	Redeemed voucher: 800259143	completed	2026-02-12 17:08:41.591047	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
35	5	7	SETTLEMENT_ACC	SUSPENSE_ACC	voucher_reverse	80.00	\N	Reversal of redemption for voucher 800259143	completed	2026-02-12 17:08:41.627453	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
36	5	3	SUSPENSE_ACC	SETTLEMENT_ACC	voucher_redeem	80.00	\N	Redeemed voucher: 800259143	completed	2026-02-12 17:21:45.071186	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
37	5	7	SETTLEMENT_ACC	SUSPENSE_ACC	voucher_reverse	80.00	\N	Reversal of redemption for voucher 800259143	completed	2026-02-12 17:21:45.239083	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
38	5	3	SUSPENSE_ACC	SETTLEMENT_ACC	voucher_redeem	80.00	\N	Redeemed voucher: 800259143	completed	2026-02-12 17:24:38.8969	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
39	5	7	SETTLEMENT_ACC	SUSPENSE_ACC	voucher_reverse	80.00	\N	Reversal of redemption for voucher 800259143	completed	2026-02-12 17:24:38.946308	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
40	5	3	SUSPENSE_ACC	SETTLEMENT_ACC	voucher_redeem	80.00	\N	Redeemed voucher: 800259143	completed	2026-02-12 17:27:13.380405	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
43	0	8	\N	\N	deposit	1000.00	DEP1771965679	\N	completed	2026-02-24 22:41:19.606838	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1771965679229
44	0	8	\N	\N	deposit	1000.00	DEP1771965931	\N	completed	2026-02-24 22:45:31.380616	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1771965931510
45	0	8	\N	\N	debit	250.00	DEB1771965931	\N	completed	2026-02-24 22:45:31.419364	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1771965931272
51	1	8	VOUCHMORPH	1234567890	interbank_deposit	1500.00	VMX-778899	\N	completed	2026-02-26 20:02:09.630331	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	IBD1772128929314
57	0	10	\N	\N	debit	80.00	466908717	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297250
58	0	10	\N	\N	debit	100.00	336080211	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297406
59	0	10	\N	\N	debit	100.00	332261062	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297457
60	0	10	\N	\N	debit	100.00	731011909	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297950
61	0	10	\N	\N	debit	100.00	699736955	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297162
62	0	10	\N	\N	debit	100.00	986081459	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297154
63	0	10	\N	\N	debit	100.00	311700170	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297648
64	0	10	\N	\N	debit	80.00	493387424	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297720
65	0	10	\N	\N	debit	100.00	261582648	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297856
66	0	10	\N	\N	debit	60.00	701437495	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297366
67	0	10	\N	\N	debit	80.00	800259143	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297788
68	0	10	\N	\N	debit	500.00	VCH1771965280434	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297218
69	0	10	\N	\N	debit	500.00	VCH1771965931488	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297349
70	0	10	\N	\N	debit	100.00	653700292	\N	completed	2026-02-26 23:11:37.474072	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEB1772140297302
72	2	0	VOUCHER-SUSPENSE	BANK:SACCUSSALIS	interbank_settlement	80.00	SET1772215749	Voucher 800259143 settlement	completed	2026-02-27 20:09:09.773113	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
73	1	0	VOUCHER-SUSPENSE	CASH	atm_cashout	500.00	328862426107	ATM cashout of voucher 328862426107 at ATM001	completed	2026-02-27 22:49:21.484115	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
74	1	8	SACCUSSALIS	1234567890	deposit	50.00	6e684380a4d6e592698cff9bd81e89dd	\N	completed	2026-02-28 13:40:30.613323	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1772278830392
75	1	8	SACCUSSALIS	1234567890	deposit	50.00	85053e4d6503fb00f4b8984d19da7c86	\N	completed	2026-02-28 13:44:09.975647	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1772279049975
76	1	8	SACCUSSALIS	1234567890	deposit	50.00	TEST_1772279125	\N	completed	2026-02-28 13:45:25.546459	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1772279125405
77	1	8	SACCUSSALIS	1234567890	deposit	50.00	ecf5e5e6188e5a4257460a582b5ff88c	\N	completed	2026-02-28 13:45:59.043476	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1772279159681
78	1	8	SACCUSSALIS	1234567890	deposit	50.00	2a1de08f8ee354fa765c4ecfaca3cd7f	\N	completed	2026-02-28 13:52:52.140387	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1772279572563
79	1	8	SACCUSSALIS	1234567890	deposit	50.00	cfbe8dcc64f9371e0730a6ba8658b340	\N	completed	2026-02-28 13:56:37.895933	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1772279797469
80	1	8	SACCUSSALIS	1234567890	deposit	50.00	c1853cebc78a0de4aabc93c671008af7	\N	completed	2026-02-28 14:06:05.817389	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	DEP1772280365165
81	1	0	VOUCHER-SUSPENSE	BANK:SACCUSSALIS	interbank_settlement	1500.00	3145a5cc507709e58a4767f6c7499984	Voucher 326059135833 settlement	completed	2026-03-02 08:16:32.695009	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
82	1	0	VOUCHER-SUSPENSE	BANK:SACCUSSALIS	interbank_settlement	1500.00	897ba2207e18cc592d82fffa28be41ff	Voucher 586361180565 settlement	completed	2026-03-02 09:02:54.598388	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
83	1	0	VOUCHER-SUSPENSE	BANK:SACCUSSALIS	interbank_settlement	1500.00	ee1c9f897a174639e584727e51268555	Voucher 458063031195 settlement	completed	2026-03-02 11:26:28.180445	0.00	0.00	0.00	0.00	0.00	f	f	f	f	\N	\N
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.users (user_id, full_name, email, password_hash, phone, role, status, created_at, password_changed_at, failed_login_attempts, last_failed_login) FROM stdin;
1	Motho	mothoyo@zurubank.com	$2y$10$CijIBJ8S8PXYNdiMUxHUFux2dlvhwAk4XU2FvrOjrD3woOCGcFQdW	+26770000000	customer	active	2025-12-29 12:44:00	\N	0	\N
2	Zurubank Settlement	settlement@zurubank.com	5ea9d71c...	+26770000010	admin	active	2025-11-10 21:33:53	\N	0	\N
3	Middleman	middleman@zurubank.com	18f424e1...	+26770000011	admin	active	2025-11-10 21:33:53	\N	0	\N
4	Cazacom	sms@cazacom.com	570e8acd...	+26770000012	admin	active	2025-11-10 21:33:53	\N	0	\N
5	Zuru System Bot	system@zurubank.com	SYSTEM_INTERNAL_ONLY	\N	internal_system	active	2026-02-12 12:23:31.375513	\N	0	\N
6	John Doe	john@example.com	test_hash	26712345678	customer	active	2026-02-24 22:41:08.177685	\N	0	\N
7	Motho	motho@example.com	test_hash	26712345678	customer	active	2026-02-24 22:44:21.238273	\N	0	\N
9	ATM System	atm@zurubank.com	$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi	ATM_SYSTEM	system	active	2026-02-27 22:46:24.604613	\N	0	\N
\.


--
-- Data for Name: voucher_cashout_details; Type: TABLE DATA; Schema: public; Owner: swap_admin
--

COPY public.voucher_cashout_details (id, voucher_number, auth_code, qr_code, barcode, amount, currency, recipient_phone, instructions, expires_at, created_at, redeemed_at, redeemed_by_user_id, redeemed_by_atm, redeemed_by_agent) FROM stdin;
1	328862426107	34621573	\N	ZCB177222457262426107	500.0000	BWP	+26771123456	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 500\n**Voucher:** 328862426107\n**PIN:** 430668\n**Expires:** 28 Feb 2026 21:36\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 328862426107\n4. Enter PIN: 430668\n5. Enter amount: BWP 500\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 328862426107\n4. Provide PIN: 430668 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-02-28 21:36:12	2026-02-27 22:36:12.232976	\N	\N	\N	\N
2	654258652742	70556470	\N	ZCB177227904958652742	100.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 100\n**Voucher:** 654258652742\n**PIN:** 177716\n**Expires:** 01 Mar 2026 12:44\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 654258652742\n4. Enter PIN: 177716\n5. Enter amount: BWP 100\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 654258652742\n4. Provide PIN: 177716 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 12:44:09	2026-02-28 13:44:09.882441	\N	\N	\N	\N
3	204327767621	01306610	\N	ZCB177227905427767621	100.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 100\n**Voucher:** 204327767621\n**PIN:** 464810\n**Expires:** 01 Mar 2026 12:44\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 204327767621\n4. Enter PIN: 464810\n5. Enter amount: BWP 100\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 204327767621\n4. Provide PIN: 464810 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 12:44:14	2026-02-28 13:44:14.751456	\N	\N	\N	\N
4	927149583563	69894646	\N	ZCB177227915849583563	100.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 100\n**Voucher:** 927149583563\n**PIN:** 400673\n**Expires:** 01 Mar 2026 12:45\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 927149583563\n4. Enter PIN: 400673\n5. Enter amount: BWP 100\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 927149583563\n4. Provide PIN: 400673 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 12:45:58	2026-02-28 13:45:58.994185	\N	\N	\N	\N
5	468530453308	20469906	\N	ZCB177227957230453308	100.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 100\n**Voucher:** 468530453308\n**PIN:** 292196\n**Expires:** 01 Mar 2026 12:52\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 468530453308\n4. Enter PIN: 292196\n5. Enter amount: BWP 100\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 468530453308\n4. Provide PIN: 292196 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 12:52:52	2026-02-28 13:52:52.089535	\N	\N	\N	\N
6	187943147702	62235869	\N	ZCB177227979743147702	100.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 100\n**Voucher:** 187943147702\n**PIN:** 302238\n**Expires:** 01 Mar 2026 12:56\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 187943147702\n4. Enter PIN: 302238\n5. Enter amount: BWP 100\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 187943147702\n4. Provide PIN: 302238 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 12:56:37	2026-02-28 13:56:37.799363	\N	\N	\N	\N
7	319886326605	94685969	\N	ZCB177228036586326605	100.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 100\n**Voucher:** 319886326605\n**PIN:** 602273\n**Expires:** 01 Mar 2026 13:06\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 319886326605\n4. Enter PIN: 602273\n5. Enter amount: BWP 100\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 319886326605\n4. Provide PIN: 602273 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 13:06:05	2026-02-28 14:06:05.754753	\N	\N	\N	\N
8	243082925322	22740095	\N	ZCB177228639182925322	1.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 1\n**Voucher:** 243082925322\n**PIN:** 167507\n**Expires:** 01 Mar 2026 14:46\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 243082925322\n4. Enter PIN: 167507\n5. Enter amount: BWP 1\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 243082925322\n4. Provide PIN: 167507 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 14:46:31	2026-02-28 15:46:30.99354	\N	\N	\N	\N
9	511264154750	85877468	\N	ZCB177228668664154750	1.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 1\n**Voucher:** 511264154750\n**PIN:** 570862\n**Expires:** 01 Mar 2026 14:51\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 511264154750\n4. Enter PIN: 570862\n5. Enter amount: BWP 1\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 511264154750\n4. Provide PIN: 570862 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 14:51:26	2026-02-28 15:51:26.135065	\N	\N	\N	\N
10	703337860851	67501075	\N	ZCB177228676737860851	100.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 100\n**Voucher:** 703337860851\n**PIN:** 853807\n**Expires:** 01 Mar 2026 14:52\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 703337860851\n4. Enter PIN: 853807\n5. Enter amount: BWP 100\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 703337860851\n4. Provide PIN: 853807 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-01 14:52:47	2026-02-28 15:52:47.582816	\N	\N	\N	\N
11	137784084242	98411014	\N	ZCB177243506284084242	100.0000	BWP	+26770000000	🔐 **ZuruBank Cashout Voucher**\n\n**Amount:** BWP 100\n**Voucher:** 137784084242\n**PIN:** 091829\n**Expires:** 03 Mar 2026 08:04\n\n**How to cash out:**\n\n🏧 **ATMs:**\n1. Go to ANY ZuruBank ATM\n2. Select 'Cardless Cashout'\n3. Enter voucher number: 137784084242\n4. Enter PIN: 091829\n5. Enter amount: BWP 100\n6. Collect your cash\n\n👤 **Agents:**\n1. Visit ANY ZuruBank Agent\n2. Tell them you want to cashout a voucher\n3. Provide voucher number: 137784084242\n4. Provide PIN: 091829 when asked\n5. Agent will process the cashout\n6. Collect your cash and sign receipt\n\n⏰ **Valid for 24 hours only**\n🔒 Keep this information secure!	2026-03-03 08:04:22	2026-03-02 09:04:22.909753	\N	\N	\N	\N
\.


--
-- Data for Name: wallet_locks; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.wallet_locks (id, wallet_id, trace_number, amount, status, expires_at, created_at) FROM stdin;
\.


--
-- Data for Name: zurubank_middleman; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.zurubank_middleman (id, account_number, api_key, created_at) FROM stdin;
\.


--
-- Name: account_freezes_freeze_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.account_freezes_freeze_id_seq', 1, false);


--
-- Name: accounts_account_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.accounts_account_id_seq', 10, true);


--
-- Name: api_keys_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.api_keys_id_seq', 4, true);


--
-- Name: api_message_logs_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.api_message_logs_log_id_seq', 1, false);


--
-- Name: atm_dispenses_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.atm_dispenses_id_seq', 1, true);


--
-- Name: atm_requests_atm_request_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.atm_requests_atm_request_id_seq', 1, false);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.audit_logs_id_seq', 5, true);


--
-- Name: cashouts_cashout_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.cashouts_cashout_id_seq', 13, true);


--
-- Name: central_bank_link_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.central_bank_link_id_seq', 1, false);


--
-- Name: disaster_recovery_tests_test_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.disaster_recovery_tests_test_id_seq', 1, false);


--
-- Name: external_banks_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.external_banks_id_seq', 1, false);


--
-- Name: hold_transactions_hold_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hold_transactions_hold_id_seq', 1, false);


--
-- Name: incoming_pre_advice_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.incoming_pre_advice_id_seq', 2, true);


--
-- Name: instant_money_transactions_transaction_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.instant_money_transactions_transaction_id_seq', 1, false);


--
-- Name: instant_money_transfers_transfer_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.instant_money_transfers_transfer_id_seq', 1, false);


--
-- Name: instant_money_vouchers_voucher_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.instant_money_vouchers_voucher_id_seq', 38, true);


--
-- Name: instant_money_wallets_wallet_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.instant_money_wallets_wallet_id_seq', 1, false);


--
-- Name: interbank_clearing_positions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.interbank_clearing_positions_id_seq', 1, true);


--
-- Name: journals_journal_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.journals_journal_id_seq', 11, true);


--
-- Name: kyc_profiles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.kyc_profiles_id_seq', 1, false);


--
-- Name: ledger_accounts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.ledger_accounts_id_seq', 5, true);


--
-- Name: network_authorizations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.network_authorizations_id_seq', 1, false);


--
-- Name: participants_participant_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.participants_participant_id_seq', 39, true);


--
-- Name: processed_deposits_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.processed_deposits_id_seq', 1, true);


--
-- Name: sessions_session_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.sessions_session_id_seq', 3, true);


--
-- Name: settlement_instructions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.settlement_instructions_id_seq', 25, true);


--
-- Name: swap_audit_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_audit_id_seq', 1, false);


--
-- Name: swap_internal_accounts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_internal_accounts_id_seq', 1, false);


--
-- Name: swap_ledger_ledger_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_ledger_ledger_id_seq', 17, true);


--
-- Name: swap_ledgers_ledger_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_ledgers_ledger_id_seq', 1, true);


--
-- Name: swap_linked_banks_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_linked_banks_id_seq', 2, true);


--
-- Name: swap_middleman_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_middleman_id_seq', 1, false);


--
-- Name: swap_requests_swap_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_requests_swap_id_seq', 1, false);


--
-- Name: swap_settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_settings_id_seq', 1, false);


--
-- Name: swap_transactions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.swap_transactions_id_seq', 1, false);


--
-- Name: transaction_reversals_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.transaction_reversals_id_seq', 1, false);


--
-- Name: transactions_transaction_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.transactions_transaction_id_seq', 83, true);


--
-- Name: users_user_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.users_user_id_seq', 9, true);


--
-- Name: voucher_cashout_details_id_seq; Type: SEQUENCE SET; Schema: public; Owner: swap_admin
--

SELECT pg_catalog.setval('public.voucher_cashout_details_id_seq', 11, true);


--
-- Name: wallet_locks_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.wallet_locks_id_seq', 1, false);


--
-- Name: zurubank_middleman_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.zurubank_middleman_id_seq', 1, false);


--
-- Name: account_freezes account_freezes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.account_freezes
    ADD CONSTRAINT account_freezes_pkey PRIMARY KEY (freeze_id);


--
-- Name: accounting_closures accounting_closures_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounting_closures
    ADD CONSTRAINT accounting_closures_pkey PRIMARY KEY (closure_date);


--
-- Name: accounts accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_pkey PRIMARY KEY (account_id);


--
-- Name: api_keys api_keys_api_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_api_key_key UNIQUE (api_key);


--
-- Name: api_keys api_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_pkey PRIMARY KEY (id);


--
-- Name: api_message_logs api_message_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_message_logs
    ADD CONSTRAINT api_message_logs_pkey PRIMARY KEY (log_id);


--
-- Name: atm_dispenses atm_dispenses_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_dispenses
    ADD CONSTRAINT atm_dispenses_pkey PRIMARY KEY (id);


--
-- Name: atm_dispenses atm_dispenses_trace_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_dispenses
    ADD CONSTRAINT atm_dispenses_trace_number_key UNIQUE (trace_number);


--
-- Name: atm_requests atm_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_requests
    ADD CONSTRAINT atm_requests_pkey PRIMARY KEY (atm_request_id);


--
-- Name: atm_terminals atm_terminals_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.atm_terminals
    ADD CONSTRAINT atm_terminals_pkey PRIMARY KEY (atm_id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: cashouts cashouts_cashout_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cashouts
    ADD CONSTRAINT cashouts_cashout_reference_key UNIQUE (cashout_reference);


--
-- Name: cashouts cashouts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cashouts
    ADD CONSTRAINT cashouts_pkey PRIMARY KEY (cashout_id);


--
-- Name: central_bank_link central_bank_link_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.central_bank_link
    ADD CONSTRAINT central_bank_link_pkey PRIMARY KEY (id);


--
-- Name: chart_of_accounts chart_of_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.chart_of_accounts
    ADD CONSTRAINT chart_of_accounts_pkey PRIMARY KEY (coa_code);


--
-- Name: data_retention_policies data_retention_policies_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.data_retention_policies
    ADD CONSTRAINT data_retention_policies_pkey PRIMARY KEY (entity_name);


--
-- Name: disaster_recovery_tests disaster_recovery_tests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.disaster_recovery_tests
    ADD CONSTRAINT disaster_recovery_tests_pkey PRIMARY KEY (test_id);


--
-- Name: external_banks external_banks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.external_banks
    ADD CONSTRAINT external_banks_pkey PRIMARY KEY (id);


--
-- Name: hold_transactions hold_transactions_hold_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_hold_reference_key UNIQUE (hold_reference);


--
-- Name: hold_transactions hold_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_pkey PRIMARY KEY (hold_id);


--
-- Name: idempotency_keys idempotency_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.idempotency_keys
    ADD CONSTRAINT idempotency_keys_pkey PRIMARY KEY (key_value);


--
-- Name: incoming_pre_advice incoming_pre_advice_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.incoming_pre_advice
    ADD CONSTRAINT incoming_pre_advice_pkey PRIMARY KEY (id);


--
-- Name: incoming_pre_advice incoming_pre_advice_trace_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.incoming_pre_advice
    ADD CONSTRAINT incoming_pre_advice_trace_number_key UNIQUE (trace_number);


--
-- Name: instant_money_transactions instant_money_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_transactions
    ADD CONSTRAINT instant_money_transactions_pkey PRIMARY KEY (transaction_id);


--
-- Name: instant_money_transfers instant_money_transfers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_transfers
    ADD CONSTRAINT instant_money_transfers_pkey PRIMARY KEY (transfer_id);


--
-- Name: instant_money_vouchers instant_money_vouchers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_vouchers
    ADD CONSTRAINT instant_money_vouchers_pkey PRIMARY KEY (voucher_id);


--
-- Name: instant_money_wallets instant_money_wallets_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_wallets
    ADD CONSTRAINT instant_money_wallets_pkey PRIMARY KEY (wallet_id);


--
-- Name: interbank_clearing_positions interbank_clearing_positions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.interbank_clearing_positions
    ADD CONSTRAINT interbank_clearing_positions_pkey PRIMARY KEY (id);


--
-- Name: journals journals_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.journals
    ADD CONSTRAINT journals_pkey PRIMARY KEY (journal_id);


--
-- Name: journals journals_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.journals
    ADD CONSTRAINT journals_reference_key UNIQUE (reference);


--
-- Name: kyc_profiles kyc_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kyc_profiles
    ADD CONSTRAINT kyc_profiles_pkey PRIMARY KEY (id);


--
-- Name: kyc_profiles kyc_profiles_user_id_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kyc_profiles
    ADD CONSTRAINT kyc_profiles_user_id_key UNIQUE (user_id);


--
-- Name: ledger_accounts ledger_accounts_account_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_account_number_key UNIQUE (account_number);


--
-- Name: ledger_accounts ledger_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ledger_accounts
    ADD CONSTRAINT ledger_accounts_pkey PRIMARY KEY (id);


--
-- Name: network_authorizations network_authorizations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_authorizations
    ADD CONSTRAINT network_authorizations_pkey PRIMARY KEY (id);


--
-- Name: network_authorizations network_authorizations_trace_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.network_authorizations
    ADD CONSTRAINT network_authorizations_trace_number_key UNIQUE (trace_number);


--
-- Name: participants participants_name_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.participants
    ADD CONSTRAINT participants_name_key UNIQUE (name);


--
-- Name: participants participants_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.participants
    ADD CONSTRAINT participants_pkey PRIMARY KEY (participant_id);


--
-- Name: processed_deposits processed_deposits_deposit_ref_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.processed_deposits
    ADD CONSTRAINT processed_deposits_deposit_ref_key UNIQUE (deposit_ref);


--
-- Name: processed_deposits processed_deposits_idempotency_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.processed_deposits
    ADD CONSTRAINT processed_deposits_idempotency_key_key UNIQUE (idempotency_key);


--
-- Name: processed_deposits processed_deposits_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.processed_deposits
    ADD CONSTRAINT processed_deposits_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (session_id);


--
-- Name: settlement_instructions settlement_instructions_idempotency_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_instructions
    ADD CONSTRAINT settlement_instructions_idempotency_key_key UNIQUE (idempotency_key);


--
-- Name: settlement_instructions settlement_instructions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_instructions
    ADD CONSTRAINT settlement_instructions_pkey PRIMARY KEY (id);


--
-- Name: settlement_instructions settlement_instructions_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.settlement_instructions
    ADD CONSTRAINT settlement_instructions_reference_key UNIQUE (reference);


--
-- Name: swap_audit swap_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_audit
    ADD CONSTRAINT swap_audit_pkey PRIMARY KEY (id);


--
-- Name: swap_internal_accounts swap_internal_accounts_account_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_internal_accounts
    ADD CONSTRAINT swap_internal_accounts_account_code_key UNIQUE (account_code);


--
-- Name: swap_internal_accounts swap_internal_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_internal_accounts
    ADD CONSTRAINT swap_internal_accounts_pkey PRIMARY KEY (id);


--
-- Name: swap_ledger swap_ledger_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledger
    ADD CONSTRAINT swap_ledger_pkey PRIMARY KEY (ledger_id);


--
-- Name: swap_ledgers swap_ledgers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledgers
    ADD CONSTRAINT swap_ledgers_pkey PRIMARY KEY (ledger_id);


--
-- Name: swap_ledgers swap_ledgers_swap_reference_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledgers
    ADD CONSTRAINT swap_ledgers_swap_reference_key UNIQUE (swap_reference);


--
-- Name: swap_linked_banks swap_linked_banks_bank_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_linked_banks
    ADD CONSTRAINT swap_linked_banks_bank_code_key UNIQUE (bank_code);


--
-- Name: swap_linked_banks swap_linked_banks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_linked_banks
    ADD CONSTRAINT swap_linked_banks_pkey PRIMARY KEY (id);


--
-- Name: swap_middleman swap_middleman_account_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_middleman
    ADD CONSTRAINT swap_middleman_account_number_key UNIQUE (account_number);


--
-- Name: swap_middleman swap_middleman_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_middleman
    ADD CONSTRAINT swap_middleman_pkey PRIMARY KEY (id);


--
-- Name: swap_requests swap_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_requests
    ADD CONSTRAINT swap_requests_pkey PRIMARY KEY (swap_id);


--
-- Name: swap_requests swap_requests_swap_uuid_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_requests
    ADD CONSTRAINT swap_requests_swap_uuid_key UNIQUE (swap_uuid);


--
-- Name: swap_settings swap_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_settings
    ADD CONSTRAINT swap_settings_pkey PRIMARY KEY (id);


--
-- Name: swap_settings swap_settings_setting_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_settings
    ADD CONSTRAINT swap_settings_setting_key_key UNIQUE (setting_key);


--
-- Name: swap_transactions swap_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_transactions
    ADD CONSTRAINT swap_transactions_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY (setting_key);


--
-- Name: transaction_reversals transaction_reversals_original_trace_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transaction_reversals
    ADD CONSTRAINT transaction_reversals_original_trace_key UNIQUE (original_trace);


--
-- Name: transaction_reversals transaction_reversals_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transaction_reversals
    ADD CONSTRAINT transaction_reversals_pkey PRIMARY KEY (id);


--
-- Name: transaction_reversals transaction_reversals_reversal_trace_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transaction_reversals
    ADD CONSTRAINT transaction_reversals_reversal_trace_key UNIQUE (reversal_trace);


--
-- Name: transactions transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.transactions
    ADD CONSTRAINT transactions_pkey PRIMARY KEY (transaction_id);


--
-- Name: accounts uq_accounts_account_number; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT uq_accounts_account_number UNIQUE (account_number);


--
-- Name: users users_email_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_key UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: voucher_cashout_details voucher_cashout_details_auth_code_key; Type: CONSTRAINT; Schema: public; Owner: swap_admin
--

ALTER TABLE ONLY public.voucher_cashout_details
    ADD CONSTRAINT voucher_cashout_details_auth_code_key UNIQUE (auth_code);


--
-- Name: voucher_cashout_details voucher_cashout_details_pkey; Type: CONSTRAINT; Schema: public; Owner: swap_admin
--

ALTER TABLE ONLY public.voucher_cashout_details
    ADD CONSTRAINT voucher_cashout_details_pkey PRIMARY KEY (id);


--
-- Name: voucher_cashout_details voucher_cashout_details_voucher_number_key; Type: CONSTRAINT; Schema: public; Owner: swap_admin
--

ALTER TABLE ONLY public.voucher_cashout_details
    ADD CONSTRAINT voucher_cashout_details_voucher_number_key UNIQUE (voucher_number);


--
-- Name: wallet_locks wallet_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallet_locks
    ADD CONSTRAINT wallet_locks_pkey PRIMARY KEY (id);


--
-- Name: wallet_locks wallet_locks_trace_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallet_locks
    ADD CONSTRAINT wallet_locks_trace_number_key UNIQUE (trace_number);


--
-- Name: zurubank_middleman zurubank_middleman_account_number_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zurubank_middleman
    ADD CONSTRAINT zurubank_middleman_account_number_key UNIQUE (account_number);


--
-- Name: zurubank_middleman zurubank_middleman_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zurubank_middleman
    ADD CONSTRAINT zurubank_middleman_pkey PRIMARY KEY (id);


--
-- Name: idx_api_logs_created; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_created ON public.api_message_logs USING btree (created_at);


--
-- Name: idx_api_logs_message_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_message_id ON public.api_message_logs USING btree (message_id);


--
-- Name: idx_api_logs_participant; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_participant ON public.api_message_logs USING btree (participant_id);


--
-- Name: idx_api_logs_success; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_success ON public.api_message_logs USING btree (success) WHERE (success = false);


--
-- Name: idx_api_logs_type; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_api_logs_type ON public.api_message_logs USING btree (message_type);


--
-- Name: idx_holds_reference; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holds_reference ON public.hold_transactions USING btree (hold_reference);


--
-- Name: idx_holds_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holds_status ON public.hold_transactions USING btree (status);


--
-- Name: idx_holds_swap; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holds_swap ON public.hold_transactions USING btree (swap_reference);


--
-- Name: idx_pre_advice_trace; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_pre_advice_trace ON public.incoming_pre_advice USING btree (trace_number);


--
-- Name: idx_proc_deposits_idem; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_proc_deposits_idem ON public.processed_deposits USING btree (idempotency_key);


--
-- Name: idx_proc_deposits_ref; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_proc_deposits_ref ON public.processed_deposits USING btree (deposit_ref);


--
-- Name: idx_reversals_original; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_reversals_original ON public.transaction_reversals USING btree (original_trace);


--
-- Name: idx_settlement_debit_ref; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_settlement_debit_ref ON public.settlement_instructions USING btree (debit_ref);


--
-- Name: idx_settlement_idem; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_settlement_idem ON public.settlement_instructions USING btree (idempotency_key);


--
-- Name: idx_transactions_account; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_transactions_account ON public.transactions USING btree (account_id);


--
-- Name: idx_transactions_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_transactions_status ON public.transactions USING btree (status);


--
-- Name: idx_transactions_trace; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_transactions_trace ON public.transactions USING btree (trace_number);


--
-- Name: idx_vouchers_number; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_vouchers_number ON public.instant_money_vouchers USING btree (voucher_number);


--
-- Name: swap_ledger no_delete_swap_ledger; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER no_delete_swap_ledger BEFORE DELETE ON public.swap_ledger FOR EACH ROW EXECUTE FUNCTION public.prevent_hard_delete();


--
-- Name: transactions no_delete_transactions; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER no_delete_transactions BEFORE DELETE ON public.transactions FOR EACH ROW EXECUTE FUNCTION public.prevent_hard_delete();


--
-- Name: swap_ledger trg_enforce_journal_balance; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_enforce_journal_balance AFTER INSERT OR UPDATE ON public.swap_ledger FOR EACH ROW EXECUTE FUNCTION public.enforce_balanced_journal();


--
-- Name: hold_transactions update_hold_transactions_updated_at; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER update_hold_transactions_updated_at BEFORE UPDATE ON public.hold_transactions FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: participants update_participants_updated_at; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER update_participants_updated_at BEFORE UPDATE ON public.participants FOR EACH ROW EXECUTE FUNCTION public.update_updated_at_column();


--
-- Name: api_message_logs api_message_logs_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.api_message_logs
    ADD CONSTRAINT api_message_logs_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.participants(participant_id);


--
-- Name: accounts fk_accounts_user_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT fk_accounts_user_id FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: central_bank_link fk_cbl_bank_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.central_bank_link
    ADD CONSTRAINT fk_cbl_bank_id FOREIGN KEY (bank_id) REFERENCES public.swap_linked_banks(id);


--
-- Name: external_banks fk_eb_user_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.external_banks
    ADD CONSTRAINT fk_eb_user_id FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: account_freezes fk_freezes_account_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.account_freezes
    ADD CONSTRAINT fk_freezes_account_id FOREIGN KEY (account_id) REFERENCES public.accounts(account_id);


--
-- Name: hold_transactions fk_hold_swap; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT fk_hold_swap FOREIGN KEY (swap_reference) REFERENCES public.swap_requests(swap_uuid);


--
-- Name: instant_money_transactions fk_imt_wallet_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_transactions
    ADD CONSTRAINT fk_imt_wallet_id FOREIGN KEY (wallet_id) REFERENCES public.instant_money_wallets(wallet_id);


--
-- Name: instant_money_transfers fk_imtf_from_wallet_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_transfers
    ADD CONSTRAINT fk_imtf_from_wallet_id FOREIGN KEY (from_wallet_id) REFERENCES public.instant_money_wallets(wallet_id);


--
-- Name: instant_money_transfers fk_imtf_to_wallet_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_transfers
    ADD CONSTRAINT fk_imtf_to_wallet_id FOREIGN KEY (to_wallet_id) REFERENCES public.instant_money_wallets(wallet_id);


--
-- Name: instant_money_vouchers fk_imv_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_vouchers
    ADD CONSTRAINT fk_imv_created_by FOREIGN KEY (created_by) REFERENCES public.users(user_id);


--
-- Name: instant_money_vouchers fk_imv_redeemed_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_vouchers
    ADD CONSTRAINT fk_imv_redeemed_by FOREIGN KEY (redeemed_by) REFERENCES public.users(user_id);


--
-- Name: instant_money_wallets fk_imw_user_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.instant_money_wallets
    ADD CONSTRAINT fk_imw_user_id FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: sessions fk_sessions_user_id; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT fk_sessions_user_id FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: swap_ledger fk_swap_journal; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_ledger
    ADD CONSTRAINT fk_swap_journal FOREIGN KEY (journal_id) REFERENCES public.journals(journal_id);


--
-- Name: hold_transactions hold_transactions_destination_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_destination_participant_id_fkey FOREIGN KEY (destination_participant_id) REFERENCES public.participants(participant_id);


--
-- Name: hold_transactions hold_transactions_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hold_transactions
    ADD CONSTRAINT hold_transactions_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.participants(participant_id);


--
-- Name: kyc_profiles kyc_profiles_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.kyc_profiles
    ADD CONSTRAINT kyc_profiles_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: swap_requests swap_requests_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.swap_requests
    ADD CONSTRAINT swap_requests_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: wallet_locks wallet_locks_wallet_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.wallet_locks
    ADD CONSTRAINT wallet_locks_wallet_id_fkey FOREIGN KEY (wallet_id) REFERENCES public.instant_money_wallets(wallet_id);


--
-- PostgreSQL database dump complete
--

\unrestrict 41jcNQgcMZ570icxzyTah7HAghDGMzTAPczlduOECKWsGPCoHQUdWdzufInUvY5

