import { initializeApp, getApps } from 'firebase/app'
import {
  GoogleAuthProvider,
  createUserWithEmailAndPassword,
  deleteUser,
  getAuth,
  onAuthStateChanged,
  sendPasswordResetEmail,
  signInWithEmailAndPassword,
  signInWithPopup,
  signOut,
  type User,
} from 'firebase/auth'
import {
  addDoc,
  collection,
  deleteDoc,
  doc,
  getDoc,
  getDocs,
  getFirestore,
  onSnapshot,
  query,
  setDoc,
  where,
  type Query,
} from 'firebase/firestore'
import { getDownloadURL, getStorage, ref, uploadBytes } from 'firebase/storage'
import type { AuthInfo, Backend, Filter } from './types'

export const firebaseConfig = {
  apiKey: process.env.NEXT_PUBLIC_FIREBASE_API_KEY,
  authDomain: process.env.NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN,
  projectId: process.env.NEXT_PUBLIC_FIREBASE_PROJECT_ID,
  storageBucket: process.env.NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: process.env.NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID,
  appId: process.env.NEXT_PUBLIC_FIREBASE_APP_ID,
}

// Firestore rejects `undefined` values; all our data is plain JSON.
const clean = (data: Record<string, unknown>) => JSON.parse(JSON.stringify(data)) as Record<string, unknown>

const toInfo = (u: User): AuthInfo => ({ uid: u.uid, email: u.email ?? '', name: u.displayName ?? undefined })

export async function createFirebaseBackend(): Promise<Backend> {
  const app = getApps()[0] ?? initializeApp(firebaseConfig)
  const auth = getAuth(app)
  const fs = getFirestore(app)
  const storage = getStorage(app)

  const q = (col: string, filter?: Filter): Query =>
    filter ? query(collection(fs, col), where(filter.field, filter.op, filter.value)) : collection(fs, col)

  return {
    mode: 'firebase',
    async list<T>(col: string, filter?: Filter) {
      const snap = await getDocs(q(col, filter))
      return snap.docs.map((d) => ({ ...d.data(), id: d.id }) as T)
    },
    async get<T>(col: string, id: string) {
      const d = await getDoc(doc(fs, col, id))
      return d.exists() ? ({ ...d.data(), id: d.id } as T) : null
    },
    async set(col, id, data) {
      await setDoc(doc(fs, col, id), clean(data), { merge: true })
    },
    async add(col, data) {
      return (await addDoc(collection(fs, col), clean(data))).id
    },
    async remove(col, id) {
      await deleteDoc(doc(fs, col, id))
    },
    subscribe<T>(col: string, filter: Filter | undefined, cb: (items: T[]) => void, onError?: (e: Error) => void) {
      return onSnapshot(
        q(col, filter),
        (snap) => cb(snap.docs.map((d) => ({ ...d.data(), id: d.id }) as T)),
        (e) => onError?.(e),
      )
    },
    subscribeDoc<T>(col: string, id: string, cb: (item: T | null) => void) {
      return onSnapshot(
        doc(fs, col, id),
        (d) => cb(d.exists() ? ({ ...d.data(), id: d.id } as T) : null),
        () => cb(null),
      )
    },

    onAuth(cb) {
      return onAuthStateChanged(auth, (u) => cb(u ? toInfo(u) : null))
    },
    async signIn(email, password) {
      return toInfo((await signInWithEmailAndPassword(auth, email.trim(), password)).user)
    },
    async signUp(email, password) {
      return toInfo((await createUserWithEmailAndPassword(auth, email.trim(), password)).user)
    },
    async signInGoogle() {
      return toInfo((await signInWithPopup(auth, new GoogleAuthProvider())).user)
    },
    async signOut() {
      await signOut(auth)
    },
    async resetPassword(email) {
      await sendPasswordResetEmail(auth, email.trim())
    },
    async deleteAuthUser() {
      if (auth.currentUser) await deleteUser(auth.currentUser)
    },
    async uploadImage(file, folder) {
      const r = ref(storage, `${folder}/${Date.now()}-${file.name.replace(/[^\w.-]/g, '_')}`)
      await uploadBytes(r, file, { contentType: file.type })
      return getDownloadURL(r)
    },
  }
}
