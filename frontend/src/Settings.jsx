import { useEffect, useState } from 'react';
import { api, API_URL } from './api.js';
import { speak, stopSpeech } from './speech.js';

const PALETTES = [
  { key: 'klasik', label: 'Klasik', colors: ['#425B9A', '#76C0EC', '#FFF6DC', '#FF95A5'] },
  { key: 'senja', label: 'Senja', colors: ['#6E4F9F', '#AFC6EF', '#FAF4FF', '#FFB0C9'] },
  { key: 'mint', label: 'Mint', colors: ['#1E8A7E', '#90D9C9', '#EEFBF3', '#FF9E8B'] },
];

const DARK_THEMES = [
  { key: 'nila', label: 'Nila', colors: ['#0F162B', '#1F2B4D', '#425B9A', '#76C0EC'] },
  { key: 'ungu', label: 'Ungu', colors: ['#1A1128', '#32224A', '#6E4F9F', '#AFC6EF'] },
  { key: 'hijau', label: 'Hijau', colors: ['#07201C', '#0E3A34', '#1E8A7E', '#90D9C9'] },
];

export default function Settings() {
  const [models, setModels] = useState([]);
  const [current, setCurrent] = useState('');
  const [selected, setSelected] = useState('');
  const [hermesStatus, setHermesStatus] = useState('unknown');
  const [freeOnly, setFreeOnly] = useState(true);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState(null);
  const [error, setError] = useState(null);
  const [timeoutSec, setTimeoutSec] = useState(600);
  const [maxMb, setMaxMb] = useState(20);
  const [sttModel, setSttModel] = useState('small');
  const [sttLang, setSttLang] = useState('id');
  const [ttsEnabled, setTtsEnabled] = useState(true);
  const [ttsVoice, setTtsVoice] = useState('');
  const [ttsSpeed, setTtsSpeed] = useState(1);
  const [voices, setVoices] = useState([]);
  const [testingVoice, setTestingVoice] = useState(false);
  const [savingGeneral, setSavingGeneral] = useState(false);
  const [generalMsg, setGeneralMsg] = useState(null);
  const [palette, setPalette] = useState(() => localStorage.getItem('palette') || 'klasik');
  const [darkTheme, setDarkTheme] = useState(() => localStorage.getItem('dark-theme') || 'nila');

  useEffect(() => {
    (async () => {
      try {
        const [models, settings, ttsVoices] = await Promise.all([
          api('/api/models'),
          api('/api/settings'),
          api('/api/tts/voices').catch(() => ({ voices: [] })),
        ]);

        setModels(models.models);
        setFreeOnly(models.free_only);
        setCurrent(models.current);
        setSelected(models.current || (models.models[0] && models.models[0].id) || '');
        setHermesStatus(settings.hermes_status || 'unknown');
        if (settings.ai_timeout) setTimeoutSec(settings.ai_timeout);
        if (settings.max_upload_mb) {
          setMaxMb(settings.max_upload_mb);
          localStorage.setItem('max-upload-mb', String(settings.max_upload_mb));
        }
        if (settings.stt_model) setSttModel(settings.stt_model);
        if (settings.stt_language) setSttLang(settings.stt_language);
        setVoices(ttsVoices.voices || []);
        setTtsEnabled(Boolean(Number(settings.tts_enabled ?? 1)));
        setTtsVoice(settings.tts_voice || ttsVoices.default || '');
        if (settings.tts_speed) setTtsSpeed(settings.tts_speed);
      } catch (e) {
        setError(e.message);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const saveGeneral = async () => {
    setSavingGeneral(true);
    setGeneralMsg(null);
    setError(null);
    try {
      await api('/api/settings', {
        method: 'POST',
        body: JSON.stringify({
          ai_timeout: Number(timeoutSec),
          max_upload_mb: Number(maxMb),
          stt_model: sttModel,
          stt_language: sttLang,
          tts_enabled: ttsEnabled,
          tts_voice: ttsVoice,
          tts_speed: Number(ttsSpeed),
        }),
      });
      localStorage.setItem('max-upload-mb', String(maxMb));
      // Halaman chat mendengarkan agar preferensi TTS langsung terpakai.
      window.dispatchEvent(new Event('hermes-settings-changed'));
      setGeneralMsg('Pengaturan tersimpan.');
    } catch (e) {
      setError(e.message);
    } finally {
      setSavingGeneral(false);
    }
  };

  const testVoice = async () => {
    setGeneralMsg(null);
    setError(null);
    if (testingVoice) {
      stopSpeech();
      setTestingVoice(false);
      return;
    }
    setTestingVoice(true);
    try {
      await speak('Halo, ini contoh suara dari pengaturan suara.', { voice: ttsVoice, speed: Number(ttsSpeed) });
    } catch (e) {
      setError(e.message);
    } finally {
      setTestingVoice(false);
    }
  };

  const pickPalette = (key) => {
    setPalette(key);
    localStorage.setItem('palette', key);
    document.documentElement.dataset.palette = key;
  };

  const pickDarkTheme = (key) => {
    setDarkTheme(key);
    localStorage.setItem('dark-theme', key);
    document.documentElement.dataset.darkTheme = key;
  };

  const save = async () => {
    if (!selected) return;
    setSaving(true);
    setMessage(null);
    setError(null);
    try {
      const data = await api('/api/settings/model', {
        method: 'POST',
        body: JSON.stringify({ model: selected }),
      });
      setCurrent(data.model);
      setMessage(`Model aktif: ${data.model}`);
    } catch (e) {
      setError(e.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold">Settings</h2>
        <span
          className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${
            hermesStatus === 'online'
              ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
              : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400'
          }`}
        >
          <span className={`h-1.5 w-1.5 rounded-full ${hermesStatus === 'online' ? 'bg-emerald-500' : 'bg-red-500'}`} />
          Hermes {hermesStatus}
        </span>
      </div>

      <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
        <h3 className="text-sm font-semibold">Umum</h3>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
          Batas waktu balasan AI dan ukuran maksimal file yang boleh dikirim.
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="timeout" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Timeout AI (detik)
            </label>
            <input
              id="timeout"
              type="number"
              min={5}
              max={3600}
              value={timeoutSec}
              onChange={(e) => setTimeoutSec(e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            />
          </div>
          <div>
            <label htmlFor="maxmb" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Maksimal file (MB)
            </label>
            <input
              id="maxmb"
              type="number"
              min={1}
              value={maxMb}
              onChange={(e) => setMaxMb(e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            />
          </div>
          <div>
            <label htmlFor="sttmodel" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Model transkripsi (audio)
            </label>
            <select
              id="sttmodel"
              value={sttModel}
              onChange={(e) => setSttModel(e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            >
              <option value="tiny">tiny (tercepat, kurang akurat)</option>
              <option value="base">base</option>
              <option value="small">small (disarankan)</option>
              <option value="medium">medium (lebih akurat, lambat)</option>
              <option value="large-v3">large-v3 (paling akurat, paling lambat)</option>
            </select>
          </div>
          <div>
            <label htmlFor="sttlang" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Bahasa audio
            </label>
            <select
              id="sttlang"
              value={sttLang}
              onChange={(e) => setSttLang(e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            >
              <option value="id">Indonesia</option>
              <option value="en">Inggris</option>
              <option value="ms">Melayu</option>
              <option value="auto">Deteksi otomatis</option>
            </select>
          </div>
          <div>
            <label htmlFor="ttsvoice" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Suara AI (text-to-speech)
            </label>
            <select
              id="ttsvoice"
              value={ttsVoice}
              onChange={(e) => setTtsVoice(e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            >
              {voices.length === 0 && <option value="">(tidak ada model suara)</option>}
              {voices.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.id} ({v.language})
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="ttsspeed" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Kecepatan suara (0.5–2)
            </label>
            <input
              id="ttsspeed"
              type="number"
              min={0.5}
              max={2}
              step={0.1}
              value={ttsSpeed}
              onChange={(e) => setTtsSpeed(e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            />
          </div>
        </div>
        <label className="mt-3 flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
          <input
            type="checkbox"
            checked={ttsEnabled}
            onChange={(e) => setTtsEnabled(e.target.checked)}
            className="h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand dark:border-slate-600"
          />
          Bacakan otomatis balasan di mode suara
        </label>
        <button
          type="button"
          onClick={saveGeneral}
          disabled={savingGeneral}
          className="mt-3 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
        >
          {savingGeneral ? 'Menyimpan...' : 'Simpan pengaturan'}
        </button>
        <button
          type="button"
          onClick={testVoice}
          disabled={!ttsVoice}
          className="mt-3 ml-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-cream disabled:opacity-40 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-brand-800"
        >
          {testingVoice ? 'Hentikan tes' : 'Tes suara'}
        </button>
        {generalMsg && <p className="mt-3 text-sm text-emerald-600 dark:text-emerald-400">{generalMsg}</p>}
      </div>

      <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
        <h3 className="text-sm font-semibold">Tema tampilan</h3>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Pilih kombinasi warna UI.</p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          {PALETTES.map((p) => (
            <button
              key={p.key}
              type="button"
              onClick={() => pickPalette(p.key)}
              className={`rounded-xl border p-3 text-left transition ${
                palette === p.key
                  ? 'border-brand ring-2 ring-brand/40 dark:border-skyblue dark:ring-skyblue/40'
                  : 'border-slate-200 hover:border-brand/50 dark:border-slate-700'
              }`}
            >
              <span className="text-sm font-medium">{p.label}</span>
              <span className="mt-2 flex gap-1">
                {p.colors.map((c) => (
                  <span key={c} className="h-5 w-5 rounded-full ring-1 ring-black/10" style={{ backgroundColor: c }} />
                ))}
              </span>
            </button>
          ))}
        </div>
      </div>

      <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
        <h3 className="text-sm font-semibold">Tema gelap</h3>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
          Warna UI saat mode gelap aktif. Terpisah dari tema di atas (yang berlaku di mode terang).
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          {DARK_THEMES.map((p) => (
            <button
              key={p.key}
              type="button"
              onClick={() => pickDarkTheme(p.key)}
              className={`rounded-xl border p-3 text-left transition ${
                darkTheme === p.key
                  ? 'border-brand ring-2 ring-brand/40 dark:border-skyblue dark:ring-skyblue/40'
                  : 'border-slate-200 hover:border-brand/50 dark:border-slate-700'
              }`}
            >
              <span className="text-sm font-medium">{p.label}</span>
              <span className="mt-2 flex gap-1">
                {p.colors.map((c) => (
                  <span key={c} className="h-5 w-5 rounded-full ring-1 ring-black/10" style={{ backgroundColor: c }} />
                ))}
              </span>
            </button>
          ))}
        </div>
      </div>

      <div className="mt-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 dark:bg-brand-900 dark:ring-slate-700">
        <h3 className="text-sm font-semibold">Model AI aktif</h3>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
          Daftar model diambil langsung dari Hermes Agent ({API_URL}/api/models). Menggunakan model dengan lencana{' '}
          <span className="font-medium text-emerald-600 dark:text-emerald-400">Free</span>
          {freeOnly ? '.' : ' — tidak ada model dengan lencana Free yang terdeteksi, menampilkan semua.'}
        </p>

        {loading ? (
          <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">Loading models...</p>
        ) : models.length === 0 ? (
          <p className="mt-4 text-sm text-red-500">Tidak ada model yang ditemukan. Pastikan Hermes gateway aktif.</p>
        ) : (
          <div className="mt-4">
            <label htmlFor="model-select" className="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
              Pilih model
            </label>
            <select
              id="model-select"
              value={selected}
              onChange={(e) => setSelected(e.target.value)}
              className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 dark:border-slate-700 dark:bg-brand-800 dark:focus:border-skyblue"
            >
              {models.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.id}
                  {m.free ? ' (Free)' : ''}
                </option>
              ))}
            </select>

            <div className="mt-3">
              <p className="text-xs text-slate-500 dark:text-slate-400">
                Model aktif sekarang:{' '}
                <span className="font-mono text-slate-700 dark:text-slate-200">{current || '—'}</span>
              </p>
              <button
                type="button"
                onClick={save}
                disabled={saving || !selected || selected === current}
                className="mt-3 rounded-xl bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-white dark:text-slate-900 dark:hover:bg-brand-100"
              >
                {saving ? 'Menyimpan...' : selected === current ? 'Model aktif' : 'Simpan model'}
              </button>
            </div>

            {message && <p className="mt-3 text-sm text-emerald-600 dark:text-emerald-400">{message}</p>}
            {error && <p className="mt-3 text-sm text-red-500">{error}</p>}
          </div>
        )}
      </div>
    </div>
  );
}