-- Schaatskompas database basis voor Supabase (PostgreSQL).
-- Supabase Auth beheert accounts; wachtwoorden horen nooit in deze tabellen.
create extension if not exists pgcrypto;

create table if not exists public.groups (
  id uuid primary key default gen_random_uuid(),
  owner_id uuid not null references auth.users(id) on delete cascade,
  name text not null check (char_length(trim(name)) between 1 and 60),
  created_at timestamptz not null default now(),
  unique (id, owner_id)
);

create table if not exists public.children (
  id uuid primary key default gen_random_uuid(),
  group_id uuid not null,
  owner_id uuid not null references auth.users(id) on delete cascade,
  first_name text not null check (char_length(trim(first_name)) between 1 and 60),
  age_band text,
  membership_status text not null default 'active'
    check (membership_status in ('active', 'former')),
  left_at timestamptz,
  created_at timestamptz not null default now(),
  unique (id, group_id, owner_id),
  foreign key (group_id, owner_id) references public.groups(id, owner_id) on delete cascade
);

create table if not exists public.training_plans (
  id uuid primary key default gen_random_uuid(),
  group_id uuid not null,
  owner_id uuid not null references auth.users(id) on delete cascade,
  name text not null check (char_length(trim(name)) between 1 and 100),
  starts_on date not null,
  ends_on date not null,
  goals text not null default '' check (char_length(goals) <= 4000),
  created_at timestamptz not null default now(),
  unique (id, group_id, owner_id),
  check (ends_on >= starts_on),
  foreign key (group_id, owner_id) references public.groups(id, owner_id) on delete cascade
);

create table if not exists public.training_sessions (
  id uuid primary key default gen_random_uuid(),
  group_id uuid not null,
  owner_id uuid not null references auth.users(id) on delete cascade,
  training_plan_id uuid,
  trained_on date not null default current_date,
  topic text not null check (char_length(trim(topic)) between 1 and 100),
  notes text not null default '' check (char_length(notes) <= 2000),
  status text not null default 'completed' check (status in ('planned', 'completed')),
  workout jsonb not null default '{}'::jsonb check (jsonb_typeof(workout) = 'object'),
  created_at timestamptz not null default now(),
  unique (id, group_id, owner_id),
  foreign key (group_id, owner_id) references public.groups(id, owner_id) on delete cascade,
  constraint training_sessions_plan_group_owner_fkey
    foreign key (training_plan_id, group_id, owner_id)
    references public.training_plans(id, group_id, owner_id) on delete no action
);

alter table public.training_sessions
  add column if not exists training_plan_id uuid,
  add column if not exists status text not null default 'completed'
    check (status in ('planned', 'completed')),
  add column if not exists workout jsonb not null default '{}'::jsonb
    check (jsonb_typeof(workout) = 'object');

do $$
begin
  if not exists (
    select 1 from pg_constraint
     where conname = 'training_sessions_plan_group_owner_fkey'
       and conrelid = 'public.training_sessions'::regclass
  ) then
    alter table public.training_sessions
      add constraint training_sessions_plan_group_owner_fkey
      foreign key (training_plan_id, group_id, owner_id)
      references public.training_plans(id, group_id, owner_id) on delete no action;
  end if;
end;
$$;

create table if not exists public.child_progress (
  id uuid primary key default gen_random_uuid(),
  child_id uuid not null,
  group_id uuid not null,
  owner_id uuid not null references auth.users(id) on delete cascade,
  skill text not null check (char_length(trim(skill)) between 1 and 80),
  level text not null check (level in ('Nog oefenen', 'In ontwikkeling', 'Beheerst')),
  updated_at timestamptz not null default now(),
  unique (child_id, skill),
  foreign key (child_id, group_id, owner_id)
    references public.children(id, group_id, owner_id) on delete cascade
);

create table if not exists public.training_attendance (
  session_id uuid not null,
  child_id uuid not null,
  group_id uuid not null,
  owner_id uuid not null references auth.users(id) on delete cascade,
  primary key (session_id, child_id),
  foreign key (session_id, group_id, owner_id)
    references public.training_sessions(id, group_id, owner_id) on delete cascade,
  foreign key (child_id, group_id, owner_id)
    references public.children(id, group_id, owner_id) on delete cascade
);

alter table public.children
  add column if not exists membership_status text not null default 'active'
    check (membership_status in ('active', 'former'));
alter table public.children
  add column if not exists left_at timestamptz;

create table if not exists public.training_activities (
  session_id uuid not null,
  group_id uuid not null,
  owner_id uuid not null references auth.users(id) on delete cascade,
  skill text not null check (char_length(trim(skill)) between 1 and 80),
  primary key (session_id, skill),
  unique (session_id, group_id, owner_id, skill),
  foreign key (session_id, group_id, owner_id)
    references public.training_sessions(id, group_id, owner_id) on delete cascade
);

create table if not exists public.training_progress (
  session_id uuid not null,
  child_id uuid not null,
  group_id uuid not null,
  owner_id uuid not null references auth.users(id) on delete cascade,
  skill text not null,
  level text not null check (level in ('Nog oefenen', 'In ontwikkeling', 'Beheerst')),
  assessed_at timestamptz not null default now(),
  primary key (session_id, child_id, skill),
  foreign key (session_id, group_id, owner_id, skill)
    references public.training_activities(session_id, group_id, owner_id, skill) on delete cascade,
  foreign key (session_id, child_id)
    references public.training_attendance(session_id, child_id) on delete cascade,
  foreign key (child_id, group_id, owner_id)
    references public.children(id, group_id, owner_id) on delete cascade
);

create table if not exists public.child_transfer_tokens (
  id uuid primary key default gen_random_uuid(),
  owner_id uuid not null references auth.users(id) on delete cascade,
  source_group_id uuid not null,
  child_id uuid not null,
  code_hash text not null unique,
  expires_at timestamptz not null default (now() + interval '24 hours'),
  accepted_at timestamptz,
  accepted_by uuid references auth.users(id),
  accepted_group_id uuid,
  created_at timestamptz not null default now(),
  check (
    (accepted_at is null and accepted_by is null and accepted_group_id is null)
    or (accepted_at is not null and accepted_by is not null and accepted_group_id is not null)
  ),
  foreign key (source_group_id, owner_id)
    references public.groups(id, owner_id) on delete cascade,
  foreign key (child_id, source_group_id, owner_id)
    references public.children(id, group_id, owner_id) on delete cascade,
  foreign key (accepted_group_id, accepted_by)
    references public.groups(id, owner_id)
);

create table if not exists public.exercise_categories (
  id text primary key,
  owner_id uuid references auth.users(id) on delete cascade,
  name text not null check (char_length(trim(name)) between 1 and 50),
  accent text not null default 'blue' check (accent in ('blue', 'orange')),
  created_at timestamptz not null default now(),
  unique (owner_id, name),
  check (owner_id is not null or id in ('plezier', 'houding', 'techniek', 'snelheid'))
);

insert into public.exercise_categories (id, name, accent)
values
  ('plezier', 'Plezier & spel', 'orange'),
  ('houding', 'Houding', 'blue'),
  ('techniek', 'Techniek', 'blue'),
  ('snelheid', 'Snelheid', 'orange')
on conflict (id) do nothing;

create table if not exists public.exercises (
  id text primary key,
  owner_id uuid not null references auth.users(id) on delete cascade,
  category_id text not null references public.exercise_categories(id) on delete restrict,
  name text not null check (char_length(trim(name)) between 1 and 100),
  duration_minutes smallint not null check (duration_minutes between 1 and 240),
  explanation text not null check (char_length(trim(explanation)) between 1 and 2000),
  video_url text check (video_url is null or (char_length(video_url) <= 2048 and video_url ~ '^https://')),
  animation_url text check (animation_url is null or (char_length(animation_url) <= 2048 and animation_url ~ '^https://')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (owner_id, category_id, name)
);

alter table public.groups enable row level security;
alter table public.children enable row level security;
alter table public.training_plans enable row level security;
alter table public.training_sessions enable row level security;
alter table public.child_progress enable row level security;
alter table public.training_attendance enable row level security;
alter table public.training_activities enable row level security;
alter table public.training_progress enable row level security;
alter table public.child_transfer_tokens enable row level security;
alter table public.exercise_categories enable row level security;
alter table public.exercises enable row level security;

drop policy if exists "Users manage their own groups" on public.groups;
create policy "Users manage their own groups"
  on public.groups for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));

drop policy if exists "Users manage their own children" on public.children;
create policy "Users manage their own children"
  on public.children for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));

drop policy if exists "Users manage their own training sessions" on public.training_sessions;
create policy "Users manage their own training sessions"
  on public.training_sessions for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));

drop policy if exists "Users manage their own training plans" on public.training_plans;
create policy "Users manage their own training plans"
  on public.training_plans for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));

drop policy if exists "Users manage their own child progress" on public.child_progress;
create policy "Users manage their own child progress"
  on public.child_progress for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));

drop policy if exists "Users manage their own attendance" on public.training_attendance;
create policy "Users manage their own attendance"
  on public.training_attendance for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));

drop policy if exists "Users manage their own training activities" on public.training_activities;
create policy "Users manage their own training activities"
  on public.training_activities for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));

drop policy if exists "Users manage their own training progress" on public.training_progress;
create policy "Users manage their own training progress"
  on public.training_progress for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));

drop policy if exists "Users read their own transfer codes" on public.child_transfer_tokens;
create policy "Users read their own transfer codes"
  on public.child_transfer_tokens for select
  using (owner_id = (select auth.uid()));

drop policy if exists "Users manage their own exercise categories" on public.exercise_categories;
create policy "Users manage their own exercise categories"
  on public.exercise_categories for all
  using (owner_id = (select auth.uid()))
  with check (owner_id = (select auth.uid()));
drop policy if exists "Users read default and their own exercise categories" on public.exercise_categories;
create policy "Users read default and their own exercise categories"
  on public.exercise_categories for select
  using (owner_id is null or owner_id = (select auth.uid()));

drop policy if exists "Users manage their own exercises" on public.exercises;
create policy "Users manage their own exercises"
  on public.exercises for all
  using (owner_id = (select auth.uid()))
  with check (
    owner_id = (select auth.uid())
    and exists (
      select 1
        from public.exercise_categories category
       where category.id = category_id
         and (category.owner_id is null or category.owner_id = (select auth.uid()))
    )
  );

create index if not exists children_group_owner_idx
  on public.children (group_id, owner_id);
create index if not exists training_sessions_group_date_idx
  on public.training_sessions (group_id, owner_id, trained_on desc);
create index if not exists training_plans_group_period_idx
  on public.training_plans (group_id, owner_id, starts_on, ends_on);
create index if not exists training_sessions_plan_date_idx
  on public.training_sessions (training_plan_id, trained_on);
create index if not exists child_progress_owner_group_idx
  on public.child_progress (owner_id, group_id);
create index if not exists training_progress_child_skill_idx
  on public.training_progress (child_id, skill, assessed_at desc);
create index if not exists child_transfer_tokens_expiry_idx
  on public.child_transfer_tokens (expires_at)
  where accepted_at is null;
create index if not exists exercises_owner_category_idx
  on public.exercises (owner_id, category_id, name);

create or replace function public.refresh_child_progress(
  p_child_id uuid,
  p_group_id uuid,
  p_owner_id uuid,
  p_skill text
)
returns void
language plpgsql
security definer
set search_path = pg_catalog, extensions, public, pg_temp
as $$
declare
  v_level text;
begin
  select tp.level
    into v_level
    from public.training_progress tp
    join public.training_sessions ts
      on ts.id = tp.session_id
     and ts.group_id = tp.group_id
     and ts.owner_id = tp.owner_id
   where tp.child_id = p_child_id
     and tp.group_id = p_group_id
     and tp.owner_id = p_owner_id
     and tp.skill = p_skill
   order by ts.trained_on desc, ts.created_at desc, tp.assessed_at desc
   limit 1;

  if found then
    insert into public.child_progress (child_id, group_id, owner_id, skill, level, updated_at)
    values (p_child_id, p_group_id, p_owner_id, p_skill, v_level, now())
    on conflict (child_id, skill)
    do update set level = excluded.level, updated_at = excluded.updated_at;
  else
    delete from public.child_progress
     where child_id = p_child_id
       and group_id = p_group_id
       and owner_id = p_owner_id
       and skill = p_skill;
  end if;
end;
$$;

create or replace function public.sync_child_progress_from_training()
returns trigger
language plpgsql
security definer
set search_path = pg_catalog, extensions, public, pg_temp
as $$
begin
  if tg_op <> 'INSERT' then
    perform public.refresh_child_progress(old.child_id, old.group_id, old.owner_id, old.skill);
  end if;
  if tg_op <> 'DELETE' then
    perform public.refresh_child_progress(new.child_id, new.group_id, new.owner_id, new.skill);
  end if;
  return null;
end;
$$;

drop trigger if exists training_progress_refresh_current on public.training_progress;
create trigger training_progress_refresh_current
  after insert or update or delete on public.training_progress
  for each row execute function public.sync_child_progress_from_training();

create or replace function public.create_child_transfer(p_child_id uuid)
returns table (code text, valid_until timestamptz)
language plpgsql
security definer
set search_path = pg_catalog, extensions, public, pg_temp
as $$
declare
  v_owner uuid := auth.uid();
  v_child public.children%rowtype;
  v_code text;
  v_expiry timestamptz := now() + interval '24 hours';
begin
  if v_owner is null then
    raise exception 'Authentication required';
  end if;

  select *
    into v_child
    from public.children
   where id = p_child_id
     and owner_id = v_owner
     and membership_status = 'active'
   for update;
  if not found then
    raise exception 'Active child not found';
  end if;

  v_code := encode(gen_random_bytes(16), 'hex');
  insert into public.child_transfer_tokens (owner_id, source_group_id, child_id, code_hash, expires_at)
  values (v_owner, v_child.group_id, v_child.id, encode(digest(v_code, 'sha256'), 'hex'), v_expiry);

  return query select v_code, v_expiry;
end;
$$;

create or replace function public.accept_child_transfer(
  p_code text,
  p_destination_group_id uuid
)
returns uuid
language plpgsql
security definer
set search_path = pg_catalog, extensions, public, pg_temp
as $$
declare
  v_owner uuid := auth.uid();
  v_token public.child_transfer_tokens%rowtype;
  v_source public.children%rowtype;
  v_destination_owner uuid;
  v_new_child_id uuid;
begin
  if v_owner is null then
    raise exception 'Authentication required';
  end if;
  if p_code is null or p_code !~ '^[a-fA-F0-9]{32}$' then
    raise exception 'Transfer code is invalid or expired';
  end if;

  select *
    into v_token
    from public.child_transfer_tokens
   where code_hash = encode(digest(lower(p_code), 'sha256'), 'hex')
     and accepted_at is null
     and expires_at > now()
   for update;
  if not found then
    raise exception 'Transfer code is invalid or expired';
  end if;
  if v_token.owner_id = v_owner then
    raise exception 'Transfer code must be accepted by another trainer';
  end if;

  select owner_id
    into v_destination_owner
    from public.groups
   where id = p_destination_group_id;
  if not found or v_destination_owner <> v_owner then
    raise exception 'Destination group not found';
  end if;

  select *
    into v_source
    from public.children
   where id = v_token.child_id
     and group_id = v_token.source_group_id
     and owner_id = v_token.owner_id
     and membership_status = 'active'
   for update;
  if not found then
    raise exception 'Child is no longer available for transfer';
  end if;

  insert into public.children (group_id, owner_id, first_name, age_band)
  values (p_destination_group_id, v_owner, v_source.first_name, v_source.age_band)
  returning id into v_new_child_id;

  insert into public.child_progress (child_id, group_id, owner_id, skill, level, updated_at)
  select v_new_child_id, p_destination_group_id, v_owner, skill, level, now()
    from public.child_progress
   where child_id = v_source.id
     and owner_id = v_source.owner_id;

  update public.children
     set membership_status = 'former',
         left_at = now()
   where id = v_source.id
     and owner_id = v_source.owner_id;

  update public.child_transfer_tokens
     set accepted_at = now(),
         accepted_by = v_owner,
         accepted_group_id = p_destination_group_id
   where id = v_token.id;

  return v_new_child_id;
end;
$$;

revoke all on function public.refresh_child_progress(uuid, uuid, uuid, text) from public, anon, authenticated;
revoke all on function public.sync_child_progress_from_training() from public, anon, authenticated;
revoke all on function public.create_child_transfer(uuid) from public, anon;
revoke all on function public.accept_child_transfer(text, uuid) from public, anon;
grant execute on function public.create_child_transfer(uuid) to authenticated;
grant execute on function public.accept_child_transfer(text, uuid) to authenticated;
