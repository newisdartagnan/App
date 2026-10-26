-- ============================================================
-- Équinoxe — RSVP, WhatsApp et check-in par QR code
-- ============================================================

alter table public.invitations
  add column if not exists phone          text,
  add column if not exists wa_opt_in      boolean not null default false,
  add column if not exists rsvp_status    text not null default 'pending',
  add column if not exists rsvp_at        timestamptz,
  add column if not exists party_size     int  not null default 1,
  add column if not exists checked_in_at  timestamptz;

do $$
begin
  if not exists (
    select 1 from pg_constraint where conname = 'invitations_rsvp_status_check'
  ) then
    alter table public.invitations
      add constraint invitations_rsvp_status_check
      check (rsvp_status in ('pending', 'yes', 'no', 'maybe'));
  end if;
end $$;

-- Numéro au format E.164 (ex : +243812345678) — sert de clé d'entrée WhatsApp.
create unique index if not exists invitations_phone_key
  on public.invitations (phone) where phone is not null;

comment on column public.invitations.wa_opt_in is
  'Consentement explicite de l''invité à être contacté sur WhatsApp.';

-- ------------------------------------------------------------
-- Journal des messages WhatsApp (audit + idempotence webhook)
-- ------------------------------------------------------------
create table if not exists public.wa_messages (
  id            uuid primary key default gen_random_uuid(),
  wa_message_id text unique,           -- id Meta, empêche le double-traitement
  invitation_id uuid references public.invitations(id) on delete set null,
  direction     text not null check (direction in ('in', 'out')),
  phone         text,
  body          text,
  created_at    timestamptz not null default now()
);

alter table public.wa_messages enable row level security;
revoke all on public.wa_messages from anon, authenticated;

-- ------------------------------------------------------------
-- RSVP depuis la SPA (clé anon) — ne touche que la ligne du token.
-- ------------------------------------------------------------
create or replace function public.set_rsvp(
  p_token      text,
  p_status     text,
  p_party_size int default 1
)
returns public.invitations
language plpgsql
security definer
set search_path = public
as $$
declare
  result public.invitations;
begin
  if p_status not in ('yes', 'no', 'maybe') then
    raise exception 'Statut RSVP invalide: %', p_status;
  end if;

  update public.invitations
     set rsvp_status = p_status,
         party_size  = greatest(1, least(coalesce(p_party_size, 1), 20)),
         rsvp_at     = now()
   where token = p_token
  returning * into result;

  if not found then
    raise exception 'Invitation introuvable';
  end if;

  return result;
end;
$$;

grant execute on function public.set_rsvp(text, text, int) to anon, authenticated;

-- ------------------------------------------------------------
-- Check-in à l'entrée (scan du QR code). Réservé au personnel :
-- exécuté par la clé service_role via une Edge Function, jamais par anon.
-- ------------------------------------------------------------
create or replace function public.check_in(p_token text)
returns public.invitations
language plpgsql
security definer
set search_path = public
as $$
declare
  result public.invitations;
begin
  update public.invitations
     set checked_in_at = coalesce(checked_in_at, now())
   where token = p_token
  returning * into result;

  if not found then
    raise exception 'Invitation introuvable';
  end if;

  return result;
end;
$$;

revoke execute on function public.check_in(text) from anon, authenticated;

-- ------------------------------------------------------------
-- Données d'exemple : numéros de démo (à remplacer par les vrais).
-- ------------------------------------------------------------
update public.invitations set phone = '+243810000001' where token = 'demo-boris' and phone is null;
update public.invitations set phone = '+243810000002' where token = 'demo-ana'   and phone is null;
