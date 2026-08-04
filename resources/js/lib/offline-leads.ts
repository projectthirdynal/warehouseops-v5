const DB_NAME = 'tecc-pwa-leads';
const DB_VERSION = 1;
const STORE_LEADS = 'leads';
const STORE_META = 'meta';

function openDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = (e) => {
      const db = (e.target as IDBOpenDBRequest).result;
      if (!db.objectStoreNames.contains(STORE_LEADS)) {
        db.createObjectStore(STORE_LEADS, { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains(STORE_META)) {
        db.createObjectStore(STORE_META, { keyPath: 'key' });
      }
    };
    req.onsuccess = (e) => resolve((e.target as IDBOpenDBRequest).result);
    req.onerror = () => reject(req.error);
  });
}

export interface CachedLead {
  id: number;
  name: string;
  product_name: string;
  amount: number;
  city: string;
  state: string;
  pool_status: string;
  assigned_at: string;
  customer_phone: string | null;
  [key: string]: unknown;
}

export async function cacheLeads(leads: CachedLead[]): Promise<void> {
  try {
    const db = await openDB();
    const tx = db.transaction([STORE_LEADS, STORE_META], 'readwrite');
    const leadStore = tx.objectStore(STORE_LEADS);
    leadStore.clear();
    for (const lead of leads) {
      leadStore.put(lead);
    }
    tx.objectStore(STORE_META).put({
      key: 'lastCached',
      value: new Date().toISOString(),
      count: leads.length,
    });
    await new Promise<void>((resolve, reject) => {
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  } catch {
    // IndexedDB may be unavailable
  }
}

export async function getCachedLeads(): Promise<{
  leads: CachedLead[];
  lastCached: string | null;
}> {
  try {
    const db = await openDB();
    const leads = await new Promise<CachedLead[]>((resolve, reject) => {
      const tx = db.transaction(STORE_LEADS, 'readonly');
      const req = tx.objectStore(STORE_LEADS).getAll();
      req.onsuccess = () => resolve(req.result ?? []);
      req.onerror = () => reject(req.error);
    });
    const meta = await new Promise<{ value: string } | undefined>((resolve, reject) => {
      const tx = db.transaction(STORE_META, 'readonly');
      const req = tx.objectStore(STORE_META).get('lastCached');
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    return { leads, lastCached: meta?.value ?? null };
  } catch {
    return { leads: [], lastCached: null };
  }
}

export async function clearCachedLeads(): Promise<void> {
  try {
    const db = await openDB();
    const tx = db.transaction([STORE_LEADS, STORE_META], 'readwrite');
    tx.objectStore(STORE_LEADS).clear();
    tx.objectStore(STORE_META).clear();
    await new Promise<void>((resolve, reject) => {
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  } catch {
    // IndexedDB may be unavailable
  }
}
