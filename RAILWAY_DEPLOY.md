# نشر Pharmassist على Railway — نسخة عرض للـ CV

هذه الخطة تنشر **نسخة تجربة** على Railway من مستودع GitHub، مع قاعدة MySQL مستقلة. لا تنقل قاعدة MySQL المحلية ولا حسابات `demo_admin` و`demo_pharmacist` ذات كلمات المرور المعروفة إلى الموقع العام.

1. **جهّز نسخة GitHub التي ستنشرها.** تأكد محلياً من `php artisan test` و`npm run build` و`composer validate --no-check-publish`. المشروع حالياً على الفرع `codex/pharmassist-workflow`، بينما `origin/main` أقدم ولا يحتوي التغييرات الأخيرة. ارفع الفرع بعد مراجعة `git status`:

   ```bash
   git add -A
   git commit -m "feat: prepare pharmacy demo for Railway"
   git push -u origin codex/pharmassist-workflow
   ```

   افتح المستودع في GitHub وتأكد أن الفرع المنشور يحتوي المجلد `database/seeders` وملف `RAILWAY_DEPLOY.md`. ملف `.env` محجوب في `.gitignore` ويجب أن يبقى محلياً.

2. **أنشئ مشروع Railway وقاعدة البيانات.** من Dashboard اختر **New Project → Empty Project**، ثم **+ New → Database → MySQL**. سمِّ الخدمة `MySQL` إن لم يكن هذا اسمها. انتظر حتى تصبح خدمة MySQL جاهزة. سيعطيها Railway متغيرات مثل `MYSQL_URL`؛ استخدم اتصالها الداخلي بين خدمات المشروع، ولا تنسخ كلمة مرور قاعدة البيانات إلى GitHub. [تعليمات MySQL الرسمية](https://docs.railway.com/databases/mysql).

3. **أضف خدمة Laravel من GitHub.** اختر **+ New → GitHub Repo → LaithYounes/Pharmassist**، ثم اضبط **Source Branch** على `codex/pharmassist-workflow`. اترك **Root Directory** جذر المستودع. اترك **Custom Build Command** و**Custom Start Command** فارغين؛ Railpack يتعرف إلى Laravel، يستخدم `public` كجذر الويب، يثبت Composer وnpm ويبني Vite تلقائياً. قد يبدأ نشر أولي عند إضافة الخدمة قبل ضبط المتغيرات؛ اضبطها ثم أعد النشر. [توثيق Railpack لـ PHP](https://railpack.com/languages/php/).

4. **أضف متغيرات خدمة Laravel قبل النشر.** في تبويب **Variables**، أدخل القيم التالية. أنشئ مفتاحاً جديداً بالأمر `php artisan key:generate --show` على جهازك، ثم الصق الناتج في `APP_KEY` داخل Railway مباشرة؛ لا تضعه في GitHub أو الدردشة. إذا كان اسم خدمة قاعدة البيانات مختلفاً، غيّر `MySQL` في مرجع `DB_URL` إلى اسمها الفعلي.

   ```dotenv
   APP_NAME=Pharmassist
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:PASTE_YOUR_NEW_PRODUCTION_KEY_HERE
   APP_TIMEZONE=Asia/Damascus
   DB_CONNECTION=mysql
   DB_URL=${{MySQL.MYSQL_URL}}
   RAILPACK_SKIP_MIGRATIONS=true
   RAILPACK_PHP_EXTENSIONS=pdo_mysql
   LOG_CHANNEL=stderr
   LOG_LEVEL=warning
   CACHE_STORE=database
   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true
   TRUSTED_PROXIES=*
   QUEUE_CONNECTION=sync
   MAIL_MAILER=log
   TELESCOPE_ENABLED=false
   ```

   `RAILPACK_SKIP_MIGRATIONS=true` مهم هنا: سكربت بدء Laravel الافتراضي في Railpack قد يشغّل migrations **وseeders** تلقائياً. سنشغّل migrations وحدها قبل النشر، ثم نضيف بيانات الكتالوج عمداً بعده. الحسابات التجريبية ذات كلمات المرور المنشورة لا تُنشأ أصلاً عندما يكون `APP_ENV=production`. [توثيق بدء Laravel في Railpack](https://railpack.com/languages/php/) و[مراجع المتغيرات بين خدمات Railway](https://docs.railway.com/variables/reference).

5. **اضبط إجراء ما قبل النشر والفحص.** في إعدادات خدمة Laravel ضع **Pre-Deploy Command**: `php artisan migrate --force`. ضع **Healthcheck Path**: `/up`. لا تضف أمر `db:seed` إلى Pre-Deploy. إذا فشلت migration، يتوقف النشر وتظهر المشكلة في Deploy Logs. [Pre-Deploy](https://docs.railway.com/deployments/pre-deploy-command) و[Healthchecks](https://docs.railway.com/deployments/healthchecks).

6. **انشر وأعطِ الخدمة رابطاً عاماً.** اضغط **Deploy** بعد مراجعة المتغيرات، وتابع **Deploy Logs** حتى تصل الخدمة إلى الحالة الصحيحة. في **Settings → Networking** اضغط **Generate Domain**. ضع `APP_URL=https://YOUR_DOMAIN.up.railway.app` باستخدام الرابط الحقيقي الناتج، ثم انشر تعديل المتغير. اختبر `https://YOUR_DOMAIN.up.railway.app/up`؛ يجب أن يعطي `200`. [خطوات GitHub الرسمية](https://docs.railway.com/guides/laravel).

7. **أضف كتالوج التجربة وأنشئ مدير الإنتاج.** ثبّت Railway CLI عند الحاجة على Windows بالأمر `npm i -g @railway/cli`، ثم `railway login` و`railway link` لاختيار المشروع والخدمة. افتح طرفية داخل الخدمة بالأمر `railway ssh`. من داخلها نفّذ:

   ```bash
   php artisan db:seed --class=DemoCatalogSeeder --force
   php artisan pharmacists:create-first-admin
   ```

   الـ seeder الأول يضع الشركات السورية والأدوية والفئات والدفعات التجريبية والموردين التجريبيين **من دون إنشاء أي مستخدم**. الأمر الثاني يطلب اسم المدير وكلمة مرور خاصة عبر إدخال مخفي. لا تستخدم كلمة مرور المدير المحلي على الموقع العام. [توثيق Railway SSH](https://docs.railway.com/cli/ssh).

8. **تحقق من النسخة المنشورة.** افتح `/api/medicines` وتأكد من ظهور الأدوية. جرّب `POST /api/Login` بحساب المدير الجديد عبر Postman، ثم طلباً محمياً مثل `/api/reports/net-sales`. يجب أن يُرفض الزائر غير المسجّل لطلبات الكتابة بـ `401`. لإنشاء صيدلي تجريبي على الموقع، استخدم طلب **إنشاء حساب صيدلي | مدير** في المجموعة؛ لا تنقل حساب `demo_pharmacist` المحلي. غيّر `baseUrl` في نسخة منفصلة من بيئة Postman إلى عنوان Railway واملأ اسم المدير وكلمة مروره هناك فقط.

9. **راجع حدود نسخة العرض.** `MAIL_MAILER=log` يعني أن رسائل طلبات التوريد لا تُرسل إلى مورد حقيقي. ملفات طلبات التوريد الخاصة المكتوبة على قرص التطبيق قد لا تبقى بعد إعادة النشر لأن قرص الخدمة مؤقت؛ إذا احتجت الاحتفاظ بها لاحقاً فجهّز تخزيناً دائماً. فعّل نسخ MySQL الاحتياطية وتابع Logs في Railway قبل تحويل المشروع إلى استخدام فعلي. [تخزين Railway](https://docs.railway.com/volumes/reference) و[نسخ البيانات الاحتياطية](https://docs.railway.com/volumes/backups).
