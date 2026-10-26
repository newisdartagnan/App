-- ============================================================
-- Équinoxe — schéma des invitations
-- ============================================================

create extension if not exists "pgcrypto";

create table if not exists public.invitations (
  id              uuid primary key default gen_random_uuid(),
  token           text not null unique,
  honorific       text,
  guest_name      text not null,
  event_title     text not null default 'Équinoxe',
  event_date      text not null,
  event_year      text not null,
  event_time      text not null,
  event_time_note text,
  event_venue     text not null,
  event_venue_note text,
  message         text not null,
  signature       text,
  created_at      timestamptz not null default now()
);

comment on column public.invitations.token is
  'Jeton opaque figurant dans le lien d''invitation (identifie l''invité).';

-- ------------------------------------------------------------
-- RLS : table totalement fermée. Aucun accès direct (ni anon ni authenticated).
-- Le seul chemin de lecture est la fonction get_invitation ci-dessous.
-- ------------------------------------------------------------
alter table public.invitations enable row level security;
revoke all on public.invitations from anon, authenticated;

-- ------------------------------------------------------------
-- Lecture ciblée par token, sans exposer la table.
-- SECURITY DEFINER => contourne la RLS mais ne renvoie que la
-- ligne dont le token correspond exactement.
-- ------------------------------------------------------------
create or replace function public.get_invitation(p_token text)
returns public.invitations
language sql
stable
security definer
set search_path = public
as $$
  select *
  from public.invitations
  where token = p_token
  limit 1;
$$;

grant execute on function public.get_invitation(text) to anon, authenticated;

-- ------------------------------------------------------------
-- Données d'exemple (le token « demo-boris » reproduit le message d'origine).
-- ------------------------------------------------------------
insert into public.invitations
  (token, honorific, guest_name, event_date, event_year, event_time,
   event_time_note, event_venue, event_venue_note, message, signature)
values
  ('demo-boris', 'M.', 'Boris Ikula',
   'Sam. 21 sept.', '2026', '18 h 30', 'Accueil dès 18 h',
   'Domaine du Lac', 'Plan à suivre',
   'Votre présence sera pour nous un honneur et rendra cette journée encore plus mémorable.',
   'Avec toute notre reconnaissance'),
  ('demo-ana', 'Mme', 'Ana Diaz',
   'Sam. 21 sept.', '2026', '18 h 30', 'Accueil dès 18 h',
   'Domaine du Lac', 'Plan à suivre',
   'Votre présence sera pour nous un honneur et rendra cette journée encore plus mémorable.',
   'Avec toute notre reconnaissance')
on conflict (token) do nothing;
