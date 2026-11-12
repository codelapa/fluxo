<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from "vue";
import axios from "axios";
import {
  LayoutDashboard,
  ArrowDownLeft,
  ArrowUpRight,
  ArrowRight,
  Upload,
  FileText,
  LogOut,
  Search,
  ChevronLeft,
  ChevronRight,
  X,
  Check,
  CloudUpload,
  RefreshCw,
  Wallet,
  ArrowDownToLine,
  ShieldCheck,
  Layers,
  AlertCircle,
  Menu,
  TrendingUp,
  CalendarDays,
  CheckCircle2,
  BookOpen,
  Pencil,
  Trash2,
  RotateCcw,
  Save,
} from "lucide-vue-next";
type User = { id: number; name: string; email: string; is_demo: boolean };
type Entry = {
  id: number;
  date: string;
  description: string;
  amount: number;
  type: "income" | "expense";
  deleted_at?: string | null;
};
type Batch = {
  id: number;
  filename: string;
  status: string;
  total_rows: number;
  processed_rows: number;
  skipped_rows: number;
  format: string;
  progress?: number;
  created_at: string;
  errors: string[] | null;
};
type Stats = {
  income: number;
  expense: number;
  balance: number;
  count: number;
  months: { month: string; income: number; expense: number }[];
};
const api = axios.create({
  baseURL: "/api",
  withCredentials: true,
  headers: { Accept: "application/json" },
});
const user = ref<User | null>(null),
  booting = ref(true),
  authBusy = ref(false),
  email = ref(""),
  password = ref(""),
  authError = ref("");
const paths: Record<string, string> = {
  overview: "/",
  transactions: "/transacoes",
  imports: "/importacoes",
  about: "/sobre-o-projeto",
};
function tabFromPath() {
  return (
    Object.keys(paths).find((key) => paths[key] === location.pathname) ||
    "overview"
  );
}
function onHistory() {
  tab.value = tabFromPath();
}
const tab = ref(tabFromPath()),
  mobile = ref(false),
  period = ref("all"),
  query = ref(""),
  kind = ref(""),
  entryStatus = ref("active"),
  page = ref(1),
  lastPage = ref(1),
  total = ref(0),
  rows = ref<Entry[]>([]),
  imports = ref<Batch[]>([]),
  stats = ref<Stats>({
    income: 0,
    expense: 0,
    balance: 0,
    count: 0,
    months: [],
  }),
  loading = ref(false),
  notice = ref(""),
  error = ref("");
const selected = ref<File | null>(null),
  uploadBusy = ref(false),
  uploadPercent = ref(0),
  dragging = ref(false),
  fileInput = ref<HTMLInputElement | null>(null),
  importPage = ref(1),
  importLast = ref(1);
let poll: ReturnType<typeof setTimeout> | undefined,
  searchTimer: ReturnType<typeof setTimeout> | undefined;
let dataSequence = 0;
let entryController: AbortController | undefined;
let importController: AbortController | undefined;
api.interceptors.response.use(
  (response) => response,
  (failure: unknown) => {
    if (axios.isAxiosError(failure) && failure.response?.status === 401) {
      user.value = null;
      clearTimeout(poll);
    }
    return Promise.reject(failure);
  },
);
const editor = ref<HTMLDialogElement | null>(null),
  editing = ref<Entry | null>(null),
  editBusy = ref(false),
  editError = ref(""),
  confirmDelete = ref(false);
const draft = ref({
  description: "",
  date: "",
  type: "income" as "income" | "expense",
  value: "",
});
async function openEditor(row: Entry) {
  editing.value = row;
  editError.value = "";
  confirmDelete.value = false;
  draft.value = {
    description: row.description,
    date: row.date.slice(0, 10),
    type: row.type,
    value:
      Math.floor(row.amount / 100) +
      "," +
      String(row.amount % 100).padStart(2, "0"),
  };
  await nextTick();
  editor.value?.showModal();
}
function closeEditor() {
  if (editBusy.value) return;
  editor.value?.close();
  editing.value = null;
  confirmDelete.value = false;
}
function cents(value: string) {
  const m = /^(\d{1,9})(?:[,.](\d{1,2}))?$/.exec(value.trim());
  return m ? Number(m[1]) * 100 + Number((m[2] || "").padEnd(2, "0")) : 0;
}
async function saveEntry() {
  if (!editing.value) return;
  const amount = cents(draft.value.value);
  if (amount < 1 || amount > 10000000000) {
    editError.value =
      "Informe um valor positivo válido, com até duas casas decimais.";
    return;
  }
  editBusy.value = true;
  editError.value = "";
  try {
    await csrf();
    await api.patch("/transactions/" + editing.value.id, {
      description: draft.value.description,
      date: draft.value.date,
      type: draft.value.type,
      amount,
    });
    editBusy.value = false;
    closeEditor();
    notice.value = "Transação atualizada. Os totais foram recalculados.";
    await load();
  } catch (e) {
    editError.value = message(e);
  } finally {
    editBusy.value = false;
  }
}
async function deleteEntry() {
  if (!editing.value) return;
  editBusy.value = true;
  editError.value = "";
  try {
    await csrf();
    await api.delete("/transactions/" + editing.value.id);
    editBusy.value = false;
    closeEditor();
    notice.value =
      "Transação excluída. Você pode restaurá-la no filtro Excluídas.";
    await load();
  } catch (e) {
    editError.value = message(e);
  } finally {
    editBusy.value = false;
  }
}
async function restoreEntry() {
  if (!editing.value) return;
  editBusy.value = true;
  editError.value = "";
  try {
    await csrf();
    await api.post("/transactions/" + editing.value.id + "/restore");
    editBusy.value = false;
    closeEditor();
    notice.value = "Transação restaurada.";
    await load();
  } catch (e) {
    editError.value = message(e);
  } finally {
    editBusy.value = false;
  }
}
const requirements = [
  {
    title: "API REST Laravel independente",
    description:
      "Laravel 12 serve endpoints JSON. O frontend está em um projeto Vue separado, sem depender de Blade ou Inertia.",
    proof: "backend/routes/api.php · Controllers → Services / Actions",
  },
  {
    title: "Autenticação e isolamento",
    description:
      "Login com sessão protegida, CSRF, limite de tentativas e acesso apenas aos dados do usuário autenticado. A entrada pública de demonstração foi desativada.",
    proof: "Sanctum · sessão HttpOnly · escopo por user_id",
  },
  {
    title: "CSV em segundo plano",
    description:
      "O upload armazena um arquivo privado e responde 202. A leitura, validação e persistência são executadas por um Worker, usando Redis como fila.",
    proof: "StartImport → ProcessImport → ImportReader / ImportWriter",
  },
  {
    title: "Dashboard e cache automático",
    description:
      "Receitas, despesas e saldo são calculados em centavos. O cache Redis dura até cinco minutos e usa uma geração de banco atualizada atomicamente por Eventos e Observers.",
    proof: "Dashboard · ImportCompleted · TransactionObserver",
  },
  {
    title: "Vue, paginação e feedback",
    description:
      "Interface em Vue 3 e TypeScript, com busca, filtros, paginação, upload e acompanhamento automático do processamento. Cada aba tem uma URL própria.",
    proof: "/ · /transacoes · /importacoes · /sobre-o-projeto",
  },
  {
    title: "Infraestrutura em Docker",
    description:
      "Serviços separados para PHP, MySQL, Redis e Worker, além de Nginx e Scheduler. Dados e arquivos privados persistem em volumes.",
    proof: "compose.yaml · PHP 8.4 · MySQL 8.4 · Redis 7.4",
  },
  {
    title: "Qualidade e testes",
    description:
      "PHPUnit cobre acesso restrito, isolamento, fila, saldo, cache, falhas, importação em lotes, OFX, duplicidades e edição, exclusão e restauração.",
    proof: "backend/tests/Feature · testes locais e em Docker",
  },
  {
    title: "Documentação e automação",
    description:
      "README de instalação, contrato OpenAPI, código em inglês e workflow para testes e build. A tela usa polling para informar conclusão da importação.",
    proof: "README.md · docs/openapi.yaml · .github/workflows/checks.yml",
    status: "Configurado",
  },
  {
    title: "Melhorias além do documento",
    description:
      "Importação OFX, proteção contra sobreposição de extratos, edição em lightbox, exclusão recuperável, identidade visual própria e visual responsivo.",
    proof: "Funcionalidades adicionais solicitadas pela CodeLapa",
  },
];
const money = (v: number) =>
  new Intl.NumberFormat("pt-BR", {
    style: "currency",
    currency: "BRL",
    maximumFractionDigits: 2,
  }).format(v / 100);
const number = (v: number) => new Intl.NumberFormat("pt-BR").format(v);
const date = (s: string) =>
  new Date(s.slice(0, 10) + "T12:00:00").toLocaleDateString("pt-BR");
const today = new Date().toLocaleDateString("pt-BR", {
  day: "2-digit",
  month: "long",
  year: "numeric",
});
const title = computed(
  () =>
    ({
      overview: "Visão geral",
      transactions: "Transações",
      imports: "Importações",
      about: "Sobre o projeto",
    })[tab.value],
);
const chart = computed(() => stats.value.months.slice(-12));
const chartMax = computed(() =>
  Math.max(1, ...chart.value.flatMap((m) => [m.income, m.expense])),
);
const expenseShare = computed(() =>
  stats.value.income + stats.value.expense
    ? Math.round(
        (stats.value.expense / (stats.value.income + stats.value.expense)) *
          100,
      )
    : 0,
);
const pending = computed(() =>
  imports.value.some((i) =>
    ["pending", "processing", "retrying"].includes(i.status),
  ),
);
const statusLabel = (s: string) =>
  ({
    pending: "Na fila",
    processing: "Processando",
    retrying: "Tentando novamente",
    completed: "Concluída",
    failed: "Revisar arquivo",
  })[s] || s;
function message(e: unknown) {
  if (axios.isAxiosError(e))
    return String(
      e.response?.data?.message ||
        "Não foi possível concluir. Verifique sua conexão e tente novamente.",
    );
  return "Não foi possível concluir a operação.";
}
async function csrf() {
  await axios.get("/sanctum/csrf-cookie", { withCredentials: true });
}
function dates() {
  const now = new Date();
  return period.value === "all"
    ? {}
    : period.value === "year"
      ? { from: now.getFullYear() + "-01-01", to: now.getFullYear() + "-12-31" }
      : {
          from:
            now.getFullYear() +
            "-" +
            String(now.getMonth() + 1).padStart(2, "0") +
            "-01",
          to: now.toISOString().slice(0, 10),
        };
}
async function authenticate() {
  authBusy.value = true;
  authError.value = "";
  try {
    await csrf();
    user.value = (
      await api.post("/login", { email: email.value, password: password.value })
    ).data;
    password.value = "";
    await load();
    schedule();
  } catch (e) {
    authError.value = message(e);
  } finally {
    authBusy.value = false;
  }
}
async function logout() {
  try {
    await csrf();
    await api.post("/logout");
    user.value = null;
    clearTimeout(poll);
    rows.value = [];
    imports.value = [];
    stats.value = { income: 0, expense: 0, balance: 0, count: 0, months: [] };
  } catch (e) {
    error.value = message(e);
  }
}
async function entries() {
  entryController?.abort();
  const c = new AbortController();
  entryController = c;
  try {
    const r = await api.get("/transactions", {
      signal: c.signal,
      params: {
        ...dates(),
        q: query.value || undefined,
        type: kind.value || undefined,
        status: tab.value === "transactions" ? entryStatus.value : "active",
        page: page.value,
        per_page: 15,
      },
    });
    rows.value = r.data.data;
    lastPage.value = r.data.last_page;
    total.value = r.data.total;
  } catch (e) {
    if (!axios.isCancel(e)) throw e;
  }
}
async function batches() {
  importController?.abort();
  const c = new AbortController();
  importController = c;
  try {
    const r = await api.get("/imports", {
      signal: c.signal,
      params: { page: importPage.value },
    });
    imports.value = r.data.data;
    importLast.value = r.data.last_page;
    for (const i of imports.value.filter((i) =>
      ["pending", "processing", "retrying"].includes(i.status),
    )) {
      const detail = await api.get("/imports/" + i.id);
      if (c.signal.aborted) return;
      Object.assign(i, detail.data);
    }
  } catch (e) {
    if (!axios.isCancel(e)) throw e;
  }
}
async function load() {
  const seq = ++dataSequence;
  loading.value = true;
  error.value = "";
  try {
    const [s] = await Promise.all([
      api.get("/dashboard", { params: dates() }),
      entries(),
      batches(),
    ]);
    if (seq === dataSequence) stats.value = s.data;
  } catch (e) {
    if (axios.isAxiosError(e) && e.response?.status === 401) user.value = null;
    else error.value = message(e);
  } finally {
    if (seq === dataSequence) loading.value = false;
  }
}
function schedule() {
  clearTimeout(poll);
  if (!user.value) return;
  poll = setTimeout(
    async () => {
      try {
        const wasPending = pending.value;
        await batches();
        if (wasPending && !pending.value) {
          await load();
          notice.value =
            "Importação atualizada. Seus resultados já estão disponíveis.";
        }
      } catch (e) {
        error.value = message(e);
      }
      schedule();
    },
    document.hidden ? 15000 : pending.value ? 2000 : 15000,
  );
}
function filter() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    page.value = 1;
    try {
      await entries();
    } catch (e) {
      error.value = message(e);
    }
  }, 300);
}
async function paginate(next: number) {
  page.value = next;
  try {
    await entries();
  } catch (e) {
    error.value = message(e);
  }
}
function choose(file?: File) {
  error.value = "";
  if (!file) return;
  if (!/\.(csv|ofx)$/i.test(file.name)) {
    error.value = "Selecione um arquivo CSV ou OFX.";
    return;
  }
  if (file.size > 20 * 1024 * 1024) {
    error.value = "O arquivo deve ter até 20 MB.";
    return;
  }
  selected.value = file;
}
function drop(e: DragEvent) {
  dragging.value = false;
  choose(e.dataTransfer?.files[0]);
}
async function upload() {
  if (!selected.value) return;
  uploadBusy.value = true;
  uploadPercent.value = 0;
  error.value = "";
  try {
    await csrf();
    const f = new FormData();
    f.append("file", selected.value);
    const r = await api.post("/imports", f, {
      onUploadProgress: (e) =>
        (uploadPercent.value = e.total
          ? Math.round((e.loaded / e.total) * 100)
          : 0),
    });
    notice.value = r.data.duplicate
      ? "Este arquivo já foi enviado. A importação existente foi preservada."
      : "Arquivo recebido. A importação está sendo processada em segundo plano.";
    selected.value = null;
    if (fileInput.value) fileInput.value.value = "";
    importPage.value = 1;
    await batches();
    schedule();
  } catch (e) {
    error.value = message(e);
  } finally {
    uploadBusy.value = false;
  }
}
async function retry(id: number) {
  try {
    await csrf();
    await api.post("/imports/" + id + "/retry");
    await batches();
    schedule();
  } catch (e) {
    error.value = message(e);
  }
}
watch(period, () => {
  page.value = 1;
  void load();
});
watch(tab, () => {
  if (location.pathname !== paths[tab.value])
    history.pushState({}, "", paths[tab.value]);
  closeEditor();
  if (tab.value === "overview") {
    entryStatus.value = "active";
    query.value = "";
    page.value = 1;
    void entries();
  }
  mobile.value = false;
  error.value = "";
});
onMounted(async () => {
  if (location.pathname !== paths[tab.value])
    history.replaceState({}, "", paths[tab.value]);
  window.addEventListener("popstate", onHistory);
  try {
    user.value = (await api.get("/user")).data;
    await load();
    schedule();
  } catch {
  } finally {
    booting.value = false;
  }
});
onUnmounted(() => {
  window.removeEventListener("popstate", onHistory);
  clearTimeout(poll);
  clearTimeout(searchTimer);
  entryController?.abort();
  importController?.abort();
});
</script>
<template>
  <div v-if="booting" class="boot" role="status">
    <img src="/logo.svg" alt="" /><span>Organizando seu espaço…</span>
  </div>
  <div v-else-if="!user" class="login-layout">
    <section class="login-story">
      <a class="brand" href="/"
        ><img src="/logo.svg" alt="" />fluxo<span>FINANCE</span></a
      >
      <div class="login-pitch">
        <span class="eyebrow">CLAREZA PARA O SEU PRÓXIMO PASSO</span>
        <h1>Mais clareza.<br /><em>Menos planilhas.</em></h1>
        <p>
          Transforme suas transações em uma visão simples, organizada e completa
          do seu dinheiro.
        </p>
        <div class="story-card">
          <div class="story-icon"><TrendingUp :size="22" /></div>
          <div>
            <strong>Cada movimento conta.</strong>
            <p>Receitas, despesas e saldo no mesmo lugar.</p>
          </div>
          <span class="story-check"><Check :size="18" /></span>
        </div>
        <div class="story-bars" aria-hidden="true">
          <i
            v-for="(h, i) in [
              25, 42, 36, 59, 50, 74, 68, 93, 85, 115, 104, 139,
            ]"
            :key="i"
            :style="{ height: h + 'px' }"
          ></i>
        </div>
      </div>
      <footer>DESENVOLVIDO POR <b>CodeLapa</b></footer>
    </section>
    <main class="login-form">
      <div class="login-box">
        <span class="login-symbol"><Wallet :size="25" /></span
        ><span class="eyebrow">BEM-VINDO AO FLUXO</span>
        <h2>Seu próximo passo<br />começa com clareza.</h2>
        <p>Acesse seu espaço financeiro.</p>
        <form @submit.prevent="authenticate()">
          <label
            >E-mail<input
              v-model="email"
              type="email"
              autocomplete="username"
              placeholder="voce@empresa.com.br"
              required /></label
          ><label
            >Senha<input
              v-model="password"
              type="password"
              autocomplete="current-password"
              placeholder="Sua senha"
              required
          /></label>
          <p v-if="authError" class="alert error" role="alert">
            {{ authError }}
          </p>
          <button class="primary full" :disabled="authBusy">
            {{ authBusy ? "Conectando…" : "Entrar na minha conta"
            }}<ArrowRight :size="18" />
          </button>
        </form>
      </div>
      <small>Fluxo · Gestão financeira com uma nova perspectiva.</small>
    </main>
  </div>
  <div v-else class="app-layout">
    <button
      v-if="mobile"
      class="menu-scrim"
      aria-label="Fechar navegação"
      @click="mobile = false"
    ></button>
    <aside :class="['sidebar', { open: mobile }]">
      <a class="brand" href="/" @click.prevent="tab = 'overview'"
        ><img src="/logo.svg" alt="" />fluxo<span>FINANCE</span></a
      ><button
        class="mobile-close icon"
        aria-label="Fechar menu"
        @click="mobile = false"
      >
        <X :size="20" />
      </button>
      <div class="workspace-name">
        <span class="workspace-initial">{{ user.name[0] }}</span>
        <div>
          <strong>Meu espaço financeiro</strong
          ><small>{{
            user.is_demo ? "Demonstração privada" : "Conta pessoal"
          }}</small>
        </div>
      </div>
      <span class="nav-label">SEU FINANCEIRO</span>
      <nav aria-label="Principal">
        <button
          :class="{ active: tab === 'overview' }"
          @click="tab = 'overview'"
        >
          <LayoutDashboard :size="19" />Visão geral</button
        ><button
          :class="{ active: tab === 'transactions' }"
          @click="tab = 'transactions'"
        >
          <ArrowRight :size="19" />Transações<span class="count">{{
            number(stats.count)
          }}</span></button
        ><button
          :class="{ active: tab === 'imports' }"
          @click="tab = 'imports'"
        >
          <Upload :size="19" />Importações<i
            v-if="pending"
            class="live-dot"
          ></i>
        </button>
        <button :class="{ active: tab === 'about' }" @click="tab = 'about'">
          <BookOpen :size="19" />Sobre o projeto
        </button>
      </nav>
      <div class="sidebar-tip">
        <span><Layers :size="20" /></span
        ><strong>Da planilha à perspectiva.</strong>
        <p>Importe seu extrato e deixe os números contarem a história.</p>
        <button @click="tab = 'imports'">
          Importar arquivo<ArrowUpRight :size="15" />
        </button>
      </div>
      <div class="sidebar-bottom">
        <span class="avatar">{{ user.name[0] }}</span>
        <div>
          <strong>{{ user.name }}</strong><small>{{
            user.is_demo ? "Conta de demonstração" : user.email
          }}</small>
        </div>
        <button class="icon" aria-label="Sair da conta" @click="logout">
          <LogOut :size="18" />
        </button>
      </div>
    </aside>
    <div class="app-main">
      <header class="topbar">
        <div>
          <button
            class="mobile-toggle icon"
            aria-label="Abrir navegação"
            :aria-expanded="mobile"
            @click="mobile = !mobile"
          >
            <Menu :size="21" /></button
          ><span class="breadcrumb"
            >Meu espaço <ChevronRight :size="13" />
            <strong>{{ title }}</strong></span
          >
        </div>
        <div class="topbar-right">
          <span class="secure"><i></i>Conexão segura</span
          ><span class="top-date"><CalendarDays :size="15" />{{ today }}</span
          ><span class="avatar">{{ user.name[0] }}</span>
        </div>
      </header>
      <main class="main-content">
        <div v-if="user.is_demo" class="demo-banner">
          <span
            ><ShieldCheck :size="16" />Modo demonstração · dados isolados e
            removidos após 48 horas.</span
          ><a href="/api/sample"
            ><ArrowDownToLine :size="14" />CSV do desafio</a
          >
        </div>
        <div class="page-heading">
          <div>
            <span class="eyebrow">{{
              tab === "overview"
                ? "SEU FINANCEIRO, COM CLAREZA"
                : tab === "transactions"
                  ? "CADA MOVIMENTO, NO SEU LUGAR"
                  : tab === "about"
                    ? "IMPLEMENTAÇÃO E CRITÉRIOS"
                    : "DA PLANILHA PARA O SEU PAINEL"
            }}</span>
            <h1>
              {{
                tab === "overview"
                  ? "Seu dinheiro, em perspectiva."
                  : tab === "transactions"
                    ? "Todos os seus movimentos."
                    : tab === "about"
                      ? "Por dentro do Fluxo."
                      : "Importe. Acompanhe. Simplifique."
              }}
            </h1>
            <p>
              {{
                tab === "overview"
                  ? "Um olhar completo sobre o que entra, o que sai e o que fica."
                  : tab === "transactions"
                    ? "Encontre e acompanhe cada transação do seu negócio."
                    : tab === "about"
                      ? "O que o documento pede e como cada requisito foi implementado."
                      : "Seus dados organizados, sem preencher linha por linha."
              }}
            </p>
          </div>
          <button
            v-if="tab === 'overview' || tab === 'transactions'"
            class="primary"
            @click="tab = 'imports'"
          >
            <Upload :size="17" />Importar arquivo
          </button>
        </div>
        <div v-if="error" class="alert error" role="alert">
          <AlertCircle :size="18" /><span>{{ error }}</span
          ><button class="icon" aria-label="Fechar erro" @click="error = ''">
            <X :size="16" />
          </button>
        </div>
        <div v-if="notice" class="alert success" role="status">
          <CheckCircle2 :size="18" /><span>{{ notice }}</span
          ><button class="icon" aria-label="Fechar aviso" @click="notice = ''">
            <X :size="16" />
          </button>
        </div>
        <template v-if="tab === 'overview' || tab === 'transactions'"
          ><div class="section-top">
            <h2>
              {{
                tab === "overview" ? "Resumo financeiro" : "Resumo do período"
              }}
            </h2>
            <div class="tools">
              <label class="period"
                ><CalendarDays :size="15" /><select
                  v-model="period"
                  aria-label="Período"
                >
                  <option value="all">Todo o período</option>
                  <option value="year">Este ano</option>
                  <option value="month">Este mês</option>
                </select></label
              ><button
                class="icon bordered"
                aria-label="Atualizar resultados"
                @click="load"
                :disabled="loading"
              >
                <RefreshCw :size="17" :class="{ spinning: loading }" />
              </button>
            </div>
          </div>
          <div class="stats-grid">
            <article class="stat-card">
              <span
                >Receitas totais<i class="metric-icon green"
                  ><ArrowDownLeft :size="20" /></i></span
              ><strong>{{ money(stats.income) }}</strong
              ><small>Entradas no período selecionado</small>
            </article>
            <article class="stat-card">
              <span
                >Despesas totais<i class="metric-icon coral"
                  ><ArrowUpRight :size="20" /></i></span
              ><strong>{{ money(stats.expense) }}</strong
              ><small>Saídas no período selecionado</small>
            </article>
            <article class="stat-card balance">
              <span
                >Saldo do período<i class="metric-icon"
                  ><Wallet :size="20" /></i></span
              ><strong>{{ money(stats.balance) }}</strong
              ><small>Receitas menos despesas</small>
              <div class="balance-orbit" aria-hidden="true"></div>
            </article>
            <article class="stat-card">
              <span
                >Transações<i class="metric-icon neutral"
                  ><Layers :size="20" /></i></span
              ><strong>{{ number(stats.count) }}</strong
              ><small>Movimentos organizados</small>
            </article>
          </div>
          <div v-if="tab === 'overview'" class="chart-grid">
            <section class="panel chart-panel">
              <div class="panel-heading">
                <div>
                  <h2>O movimento do seu dinheiro</h2>
                  <p>Receitas e despesas, mês a mês.</p>
                </div>
                <div class="legend">
                  <span><i class="income-dot"></i>Receitas</span
                  ><span><i class="expense-dot"></i>Despesas</span>
                </div>
              </div>
              <div v-if="chart.length" class="chart-wrap">
                <svg
                  viewBox="0 0 720 230"
                  role="img"
                  aria-label="Receitas e despesas por mês"
                >
                  <g v-for="y in [30, 80, 130, 180]" :key="y">
                    <line
                      x1="10"
                      :y1="y"
                      x2="710"
                      :y2="y"
                      stroke="#e8ede9"
                      stroke-dasharray="3 5"
                    />
                  </g>
                  <g v-for="(m, i) in chart" :key="m.month">
                    <rect
                      :x="15 + i * (690 / chart.length)"
                      :y="185 - (m.income / chartMax) * 150"
                      :width="Math.min(19, 240 / chart.length)"
                      :height="Math.max(1, (m.income / chartMax) * 150)"
                      rx="4"
                      fill="#23765a"
                    >
                      <title>
                        {{ m.month }} · Receitas: {{ money(m.income) }}
                      </title>
                    </rect>
                    <rect
                      :x="
                        18 +
                        Math.min(19, 240 / chart.length) +
                        i * (690 / chart.length)
                      "
                      :y="185 - (m.expense / chartMax) * 150"
                      :width="Math.min(19, 240 / chart.length)"
                      :height="Math.max(1, (m.expense / chartMax) * 150)"
                      rx="4"
                      fill="#e7cbb4"
                    >
                      <title>
                        {{ m.month }} · Despesas: {{ money(m.expense) }}
                      </title>
                    </rect>
                    <text
                      :x="26 + i * (690 / chart.length)"
                      y="211"
                      fill="#7a857f"
                      font-size="11"
                      text-anchor="middle"
                    >
                      {{
                        new Date(m.month + "-15T12:00:00")
                          .toLocaleDateString("pt-BR", { month: "short" })
                          .replace(".", "")
                      }}/{{ m.month.slice(2, 4) }}
                    </text>
                  </g>
                </svg>
                <details class="chart-data">
                  <summary>Ver dados do gráfico</summary>
                  <table>
                    <tbody>
                      <tr v-for="m in chart" :key="m.month">
                        <td>{{ m.month }}</td>
                        <td>{{ money(m.income) }}</td>
                        <td>{{ money(m.expense) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </details>
              </div>
              <div v-else class="empty-chart">
                <TrendingUp :size="32" /><strong>{{
                  pending
                    ? "Seus dados estão chegando."
                    : "Uma nova perspectiva começa aqui."
                }}</strong>
                <p>Importe seu primeiro CSV para visualizar a evolução.</p>
              </div>
            </section>
            <section class="panel distribution">
              <h2>Como seu dinheiro se divide</h2>
              <p>Participação no total movimentado.</p>
              <div class="donut">
                <svg
                  viewBox="0 0 160 160"
                  role="img"
                  :aria-label="
                    'Despesas representam ' + expenseShare + '% do volume'
                  "
                >
                  <circle
                    cx="80"
                    cy="80"
                    r="62"
                    fill="none"
                    stroke="#edf1eb"
                    stroke-width="15"
                  />
                  <circle
                    v-if="stats.count"
                    cx="80"
                    cy="80"
                    r="62"
                    fill="none"
                    stroke="#23765a"
                    stroke-width="15"
                  />
                  <circle
                    v-if="stats.count"
                    cx="80"
                    cy="80"
                    r="62"
                    fill="none"
                    stroke="#e7cbb4"
                    stroke-width="15"
                    :stroke-dasharray="`${(expenseShare / 100) * 389.56} 389.56`"
                    transform="rotate(-90 80 80)"
                  />
                </svg>
                <div>
                  <small>Movimentado</small
                  ><strong>{{ money(stats.income + stats.expense) }}</strong>
                </div>
              </div>
              <div class="distribution-row">
                <span><i class="income-dot"></i>Receitas</span
                ><strong>{{ stats.count ? 100 - expenseShare : 0 }}%</strong>
              </div>
              <div class="distribution-row">
                <span><i class="expense-dot"></i>Despesas</span
                ><strong>{{ expenseShare }}%</strong>
              </div>
            </section>
          </div>
          <section class="panel transactions">
            <div class="panel-heading">
              <div>
                <h2>
                  {{
                    tab === "overview"
                      ? "Últimas transações"
                      : "Suas transações"
                  }}
                </h2>
                <p>
                  {{
                    tab === "overview"
                      ? "Os movimentos mais recentes, em um só olhar."
                      : number(total) + " transações encontradas."
                  }}
                </p>
              </div>
              <button
                v-if="tab === 'overview'"
                class="text-button"
                @click="tab = 'transactions'"
              >
                Ver todas<ArrowUpRight :size="16" />
              </button>
            </div>
            <div v-if="tab === 'transactions'" class="table-filters">
              <label class="search"
                ><Search :size="17" /><input
                  v-model="query"
                  @input="filter"
                  placeholder="Buscar pela descrição…"
                  aria-label="Buscar transações" /></label
              ><select
                v-model="kind"
                @change="filter"
                aria-label="Tipo de transação"
              >
                <option value="">Todos os tipos</option>
                <option value="income">Receitas</option>
                <option value="expense">Despesas</option></select
              ><select
                v-model="entryStatus"
                @change="filter"
                aria-label="Situação das transações"
              >
                <option value="active">Ativas</option>
                <option value="deleted">Excluídas</option>
              </select>
            </div>
            <div class="table-scroll">
              <table v-if="rows.length">
                <thead>
                  <tr>
                    <th>DESCRIÇÃO</th>
                    <th>DATA</th>
                    <th>TIPO</th>
                    <th class="amount">VALOR</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="r in tab === 'overview' ? rows.slice(0, 5) : rows"
                    :key="r.id"
                    @click="openEditor(r)"
                    class="editable-row"
                  >
                    <td>
                      <span :class="['row-icon', r.type]"
                        ><ArrowDownLeft
                          v-if="r.type === 'income'"
                          :size="17" /><ArrowUpRight v-else :size="17" /></span
                      ><button
                        class="description transaction-name"
                        @click.stop="openEditor(r)"
                        :aria-label="
                          (r.deleted_at ? 'Restaurar ' : 'Editar ') +
                          r.description
                        "
                      >
                        {{ r.description }}<Pencil :size="13" />
                      </button>
                    </td>
                    <td>{{ date(r.date) }}</td>
                    <td>
                      <span :class="['type-pill', r.type]">{{
                        r.type === "income" ? "Receita" : "Despesa"
                      }}</span>
                    </td>
                    <td :class="['amount', r.type]">
                      {{ r.type === "income" ? "+" : "−" }}
                      {{ money(r.amount) }}
                    </td>
                  </tr>
                </tbody>
              </table>
              <div v-else class="empty">
                <FileText :size="28" /><strong>{{
                  pending
                    ? "Importação em andamento"
                    : "Nenhuma transação neste filtro"
                }}</strong>
                <p>
                  {{
                    pending
                      ? "Você pode continuar navegando. Atualizamos os resultados automaticamente."
                      : "Ajuste os filtros ou importe um arquivo CSV para começar."
                  }}
                </p>
              </div>
            </div>
            <footer v-if="tab === 'transactions'" class="pagination">
              <span
                >Página {{ page }} de {{ lastPage }} ·
                {{ number(total) }} movimentos</span
              >
              <div>
                <button
                  class="icon bordered"
                  aria-label="Página anterior"
                  :disabled="page <= 1"
                  @click="paginate(page - 1)"
                >
                  <ChevronLeft :size="18" /></button
                ><button
                  class="icon bordered"
                  aria-label="Próxima página"
                  :disabled="page >= lastPage"
                  @click="paginate(page + 1)"
                >
                  <ChevronRight :size="18" />
                </button>
              </div>
            </footer></section
        ></template>
        <template v-else-if="tab === 'imports'"
          ><div class="import-grid">
            <section class="panel upload-panel">
              <div class="panel-heading">
                <div>
                  <h2>Seu próximo arquivo</h2>
                  <p>Importe transações sem interromper seu dia.</p>
                </div>
                <span class="pill">CSV · OFX</span>
              </div>
              <div
                :class="['dropzone', { dragging }]"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="drop"
              >
                <span class="upload-symbol"><CloudUpload :size="32" /></span>
                <h3>
                  {{
                    selected ? selected.name : "Arraste seu arquivo até aqui"
                  }}
                </h3>
                <p>
                  {{
                    selected
                      ? number(Math.round(selected.size / 1024)) +
                        " KB · pronto para importar"
                      : "ou selecione um CSV ou OFX no seu computador"
                  }}
                </p>
                <input
                  ref="fileInput"
                  type="file"
                  accept=".csv,.ofx,text/csv,application/x-ofx,application/vnd.intu.qfx"
                  class="sr-only"
                  aria-label="Selecionar arquivo CSV ou OFX"
                  @change="
                    choose(($event.target as HTMLInputElement).files?.[0])
                  "
                /><button
                  class="button"
                  :disabled="uploadBusy"
                  @click="fileInput?.click()"
                >
                  {{ selected ? "Trocar arquivo" : "Selecionar arquivo"
                  }}<FileText :size="16" /></button
                ><small>CSV ou OFX · até 20 MB · 100 mil linhas</small>
              </div>
              <button
                class="primary full"
                :disabled="!selected || uploadBusy"
                @click="upload"
              >
                <Upload :size="17" />{{
                  uploadBusy
                    ? "Enviando " + uploadPercent + "%…"
                    : "Iniciar importação"
                }}<ArrowRight v-if="!uploadBusy" :size="17" />
              </button>
              <p class="upload-note">
                <ShieldCheck :size="15" />Validamos todo o arquivo antes de
                salvar. Sem importações pela metade.
              </p>
            </section>
            <aside class="import-help">
              <span class="help-icon"><FileText :size="25" /></span>
              <h2>Uma boa organização começa na origem.</h2>
              <p>
                Para CSV, use as quatro colunas do exemplo. Também aceitamos
                extratos OFX bancários e de cartão em BRL.
              </p>
              <div class="csv-example">
                <code
                  >date,description,amount,type<br />2026-05-01,Serviço,115346,Receita</code
                >
              </div>
              <ul>
                <li><Check :size="16" />Data no formato AAAA-MM-DD</li>
                <li>
                  <Check :size="16" />Valor em centavos: 115346 = R$ 1.153,46
                </li>
                <li><Check :size="16" />Tipo: Receita ou Despesa</li>
                <li>
                  <Check :size="16" />Extratos sobrepostos não duplicam
                  lançamentos identificáveis
                </li>
              </ul>
              <a href="/api/sample" class="text-button"
                >Baixar CSV do desafio<ArrowDownToLine :size="16"
              /></a>
            </aside>
          </div>
          <section class="panel imports-history">
            <div class="panel-heading">
              <div>
                <h2>Histórico de importações</h2>
                <p>Do envio ao resultado, acompanhe cada etapa.</p>
              </div>
              <span v-if="pending" class="processing-label"
                ><i class="live-dot"></i>Atualização automática</span
              >
            </div>
            <div v-for="i in imports" :key="i.id" class="import-item">
              <div class="import-file-icon"><FileText :size="23" /></div>
              <div class="import-info">
                <strong>{{ i.filename }}</strong><small
                  >{{ date(i.created_at) }} ·
                  {{
                    number(
                      i.status === "completed"
                        ? i.processed_rows + (i.skipped_rows || 0)
                        : i.progress || 0,
                    )
                  }}
                  de {{ number(i.total_rows) }} linhas</small
                >
                <div
                  v-if="
                    ['pending', 'processing', 'retrying'].includes(i.status)
                  "
                  class="progress-track"
                  role="progressbar"
                  :aria-label="'Progresso de ' + i.filename"
                  :aria-valuenow="
                    i.total_rows
                      ? Math.round(((i.progress || 0) / i.total_rows) * 100)
                      : 0
                  "
                  aria-valuemin="0"
                  aria-valuemax="100"
                >
                  <span
                    :style="{
                      width:
                        (i.total_rows
                          ? Math.max(
                              5,
                              ((i.progress || 0) / i.total_rows) * 100,
                            )
                          : 5) + '%',
                    }"
                  ></span>
                </div>
                <small v-if="i.skipped_rows" class="duplicates-note"
                  >{{ number(i.processed_rows) }} novas ·
                  {{ number(i.skipped_rows) }} duplicadas ignoradas</small
                >
                <details v-if="i.errors?.length" class="import-errors">
                  <summary>Ver o que precisa de atenção</summary>
                  <ul>
                    <li v-for="(e, n) in i.errors" :key="n">{{ e }}</li>
                  </ul>
                </details>
              </div>
              <span :class="['import-status', i.status]"
                ><CheckCircle2
                  v-if="i.status === 'completed'"
                  :size="14"
                /><RefreshCw
                  v-else-if="
                    ['pending', 'processing', 'retrying'].includes(i.status)
                  "
                  :size="14"
                  class="spinning"
                /><AlertCircle v-else :size="14" />{{
                  statusLabel(i.status)
                }}</span
              ><button
                v-if="i.status === 'failed'"
                class="icon bordered"
                :aria-label="'Tentar novamente ' + i.filename"
                @click="retry(i.id)"
              >
                <RefreshCw :size="16" />
              </button>
            </div>
            <div v-if="!imports.length" class="empty">
              <CloudUpload :size="27" /><strong
                >Seu histórico começa no primeiro arquivo.</strong
              >
              <p>Os resultados das importações aparecerão aqui.</p>
            </div>
            <footer v-if="importLast > 1" class="pagination">
              <span>Página {{ importPage }} de {{ importLast }}</span>
              <div>
                <button
                  class="icon bordered"
                  aria-label="Importações anteriores"
                  :disabled="importPage <= 1"
                  @click="
                    importPage--;
                    batches();
                  "
                >
                  <ChevronLeft :size="18" /></button
                ><button
                  class="icon bordered"
                  aria-label="Próximas importações"
                  :disabled="importPage >= importLast"
                  @click="
                    importPage++;
                    batches();
                  "
                >
                  <ChevronRight :size="18" />
                </button>
              </div>
            </footer></section
        ></template>
        <section v-if="tab === 'about'" class="project-explanation">
          <div class="project-intro panel">
            <span class="help-icon"><BookOpen :size="25" /></span>
            <div>
              <h2>Um sistema financeiro completo, com uma base sólida.</h2>
              <p>
                A proposta do teste é uma API Laravel independente, consumida
                por Vue, com importação assíncrona e cálculos confiáveis. Abaixo
                está o mapa entre o enunciado e a implementação.
              </p>
            </div>
          </div>
          <div class="requirement-grid">
            <article
              v-for="(item, index) in requirements"
              :key="item.title"
              class="panel requirement-card"
            >
              <div class="requirement-top">
                <span>{{ String(index + 1).padStart(2, "0") }}</span><small>{{
                  item.status || "Implementado"
                }}</small>
              </div>
              <h2>{{ item.title }}</h2>
              <p>{{ item.description }}</p>
              <code>{{ item.proof }}</code>
            </article>
          </div>
          <section class="panel explanation-details">
            <h2>Como evitamos duplicidades</h2>
            <ul>
              <li>
                <strong>Mesmo arquivo:</strong> um hash por usuário impede que o
                mesmo conteúdo seja processado novamente, mesmo com outro nome.
              </li>
              <li>
                <strong>CSV sobreposto:</strong> data, descrição normalizada,
                tipo e valor formam uma identidade. Linhas idênticas, inclusive
                dentro do arquivo, são ignoradas e contabilizadas no histórico.
              </li>
              <li>
                <strong>OFX:</strong> a combinação de banco, conta e FITID
                identifica o lançamento. FITIDs diferentes são preservados mesmo
                se data e valor forem iguais. O mesmo FITID em outra conta
                também é distinto.
              </li>
              <li>
                <strong>CSV e OFX:</strong> lançamentos com os mesmos quatro
                dados podem ser reconhecidos entre formatos. Se as descrições
                forem diferentes, o CSV não permite provar automaticamente que
                se trata da mesma operação.
              </li>
              <li>
                <strong>Edições e exclusões:</strong> as identidades originais
                são preservadas. Reimportar um extrato não desfaz seus ajustes
                nem restaura valores que você excluiu.
              </li>
            </ul>
            <p class="explanation-note">
              CSV não contém um identificador bancário. Dois pagamentos
              legítimos com data, descrição, tipo e valor exatamente iguais são
              tratados como repetidos. Para diferenciá-los com segurança,
              prefira OFX com FITIDs distintos.
            </p>
          </section>
          <section class="panel explanation-details">
            <h2>Valores e consistência</h2>
            <p>
              No CSV do desafio, os números inteiros são interpretados como
              centavos: 115346 corresponde a R$ 1.153,46. No OFX, 1153.46 é
              convertido para os mesmos 115346 centavos. Valores negativos do
              OFX são despesas; os positivos são receitas. Apenas BRL é aceito,
              evitando misturar moedas nos totais.
            </p>
            <p>
              Todo o arquivo é validado antes da gravação. A inserção ocorre em
              lotes de 500 dentro de uma transação de banco. Uma falha impede
              gravação parcial. As importações do mesmo usuário são serializadas
              no banco para proteger o saldo e as identidades.
            </p>
            <p>
              Exclusões são recuperáveis no filtro <strong>Excluídas</strong>.
              Alterar, excluir ou restaurar uma transação invalida o resumo
              automaticamente por Observer. Importações em lote usam um Evento,
              mantendo a atualização do cache atômica com a gravação dos dados.
            </p>
          </section>
          <section class="panel explanation-details">
            <h2>Documentação e entrega</h2>
            <p>
              O README inclui instalação via Docker, execução dos testes e
              decisões técnicas. A especificação OpenAPI documenta os endpoints.
              O workflow do GitHub Actions está configurado no código; sua
              execução depende do envio a um repositório GitHub.
            </p>
            <div class="actions">
              <a class="button" href="/api/documentation/readme"
                ><ArrowDownToLine :size="16" />Baixar README</a
              ><a class="button" href="/api/documentation/api"
                ><FileText :size="16" />Documentação da API</a
              >
            </div>
          </section>
        </section>
        <dialog
          v-if="editing"
          ref="editor"
          class="transaction-dialog"
          @cancel="closeEditor"
          @click="if ($event.target === editor) closeEditor();"
          aria-labelledby="transaction-dialog-title"
        >
          <div class="dialog-heading">
            <div>
              <span class="eyebrow">SEUS MOVIMENTOS</span>
              <h2 id="transaction-dialog-title">
                {{
                  editing.deleted_at ? "Transação excluída" : "Editar transação"
                }}
              </h2>
            </div>
            <button
              class="icon"
              aria-label="Fechar edição"
              :disabled="editBusy"
              @click="closeEditor"
            >
              <X :size="20" />
            </button>
          </div>
          <p class="dialog-description">
            {{
              editing.deleted_at
                ? "Restaure este lançamento para incluí-lo novamente nos totais."
                : "Altere os dados abaixo. O resumo financeiro será atualizado ao salvar."
            }}
          </p>
          <p v-if="editError" class="alert error" role="alert">
            {{ editError }}
          </p>
          <form @submit.prevent="saveEntry">
            <fieldset :disabled="editBusy || !!editing.deleted_at">
              <label
                >Nome / descrição<input
                  v-model="draft.description"
                  required
                  maxlength="500"
                  autofocus
              /></label>
              <div class="dialog-grid">
                <label
                  >Data<input
                    v-model="draft.date"
                    type="date"
                    required /></label
                ><label
                  >Tipo<select v-model="draft.type">
                    <option value="income">Receita</option>
                    <option value="expense">Despesa</option>
                  </select></label
                >
              </div>
              <label
                >Valor (R$)<input
                  v-model="draft.value"
                  inputmode="decimal"
                  required
                  placeholder="0,00"
              /></label>
            </fieldset>
            <div v-if="confirmDelete" class="delete-confirmation">
              <strong>Excluir esta transação?</strong>
              <p>
                Ela sairá dos totais. Você poderá restaurá-la pelo filtro
                Excluídas.
              </p>
              <div class="actions">
                <button
                  type="button"
                  class="button"
                  :disabled="editBusy"
                  @click="confirmDelete = false"
                >
                  Voltar</button
                ><button
                  type="button"
                  class="danger-button"
                  :disabled="editBusy"
                  @click="deleteEntry"
                >
                  Confirmar exclusão
                </button>
              </div>
            </div>
            <div v-else class="dialog-actions">
              <button
                v-if="!editing.deleted_at"
                type="button"
                class="text-button danger-text"
                :disabled="editBusy"
                @click="confirmDelete = true"
              >
                <Trash2 :size="16" />Excluir</button
              ><button
                type="button"
                class="button"
                :disabled="editBusy"
                @click="closeEditor"
              >
                Cancelar</button
              ><button
                v-if="editing.deleted_at"
                type="button"
                class="primary"
                :disabled="editBusy"
                @click="restoreEntry"
              >
                <RotateCcw :size="16" />Restaurar</button
              ><button v-else class="primary" :disabled="editBusy">
                <Save :size="16" />{{
                  editBusy ? "Salvando…" : "Salvar alterações"
                }}
              </button>
            </div>
          </form>
        </dialog>
        <footer class="main-footer">
          <span
            ><img src="/logo.svg" alt="" />Fluxo · Clareza em cada
            movimento.</span
          ><small>Feito pela <b>CodeLapa</b></small>
        </footer>
      </main>
    </div>
  </div>
</template>
