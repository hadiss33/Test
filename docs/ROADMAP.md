# Crons

1- Update airlines active route
// 0 0 \* \* \* every day

2- Get details flight items
// from job

3- Upsert flights route, financial and seats
// 3 \*/2 \* \* \* \* every 30 sec
// 7 \* \* \* \* \* every 1 min
// 30 15 \* \* \* \* every 15 mins
// 60 30 \* \* \* \* every 30 mins
// 90 60 \* \* \* \* every 60 mins
// 120 180 \* \* \* \* every 180 mins

4- Backup and delete flights done
// 0 0 \* \* \* every day
